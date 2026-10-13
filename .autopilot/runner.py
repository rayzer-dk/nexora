#!/usr/bin/env python3
"""Autopilot CLI supervisor: bounded unattended turns, state-driven handoffs.

Does not control already-open ChatGPT/Codex/Claude graphical conversations. It launches
fresh local Codex CLI / Claude Code CLI turns, each of which reads project state.
No automatic retry of a timed-out or ambiguous writing operation.
"""

import argparse
import datetime as dt
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import time


def iso():
    return dt.datetime.now(dt.timezone.utc).astimezone().isoformat(timespec='seconds')


def load(path):
    try:
        raw = path.read_text(encoding='utf-8').lstrip('\ufeff')
        return json.loads(raw.split('=', 1)[1].strip().rstrip(';'))
    except (OSError, ValueError, IndexError) as exc:
        raise RuntimeError('Некорректный или отсутствующий state.js: %s' % exc) from exc


def atomic_json(path, obj):
    tmp = path.with_name(path.name + '.tmp-' + str(os.getpid()))
    try:
        tmp.write_text(json.dumps(obj, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            tmp.unlink()


def pid_alive(pid):
    if not isinstance(pid, int) or pid <= 0:
        return False
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    except OSError:
        return True    # Windows: do not assume stale and start another writer


class Lock:
    def __init__(self, path):
        self.path = path
        self.token = '%s:%s' % (os.getpid(), time.time_ns())

    def __enter__(self):
        for _ in range(2):
            try:
                fd = os.open(str(self.path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                with os.fdopen(fd, 'w', encoding='utf-8') as f:
                    json.dump({'pid': os.getpid(), 'host': socket.gethostname(), 'token': self.token}, f)
                return self
            except FileExistsError:
                try:
                    data = json.loads(self.path.read_text(encoding='utf-8'))
                except (OSError, ValueError):
                    raise RuntimeError('Файл блокировки повреждён; проверьте запущенный runner перед удалением')
                if data.get('host') != socket.gethostname() or pid_alive(data.get('pid')):
                    raise RuntimeError('Диспетчер уже запущен (PID %s)' % data.get('pid'))
                # Avoid racing a simultaneous supervisor that replaced the lock.
                try:
                    if json.loads(self.path.read_text(encoding='utf-8')) == data:
                        self.path.unlink()
                except FileNotFoundError:
                    pass
        raise RuntimeError('Невозможно получить блокировку диспетчера')

    def __exit__(self, *_):
        try:
            data = json.loads(self.path.read_text(encoding='utf-8'))
            if data.get('token') == self.token:
                self.path.unlink()
        except (OSError, ValueError):
            pass


def state_key(s):
    """Only committed ledger changes count as state progress; heartbeat is separate."""
    return (s.get('dir'), s.get('updatedAt'), s.get('finishedAt'),
            tuple((x.get('id'), x.get('status'), x.get('finishedAt')) for x in s.get('stages') or []),
            tuple((x.get('id'), x.get('status'), x.get('repairs'), x.get('retries')) for x in s.get('tickets') or []),
            len(s.get('log') or []), bool(s.get('waiting')))


def pending_action(s):
    """Useful hints only: the agent remains responsible for checks, reviews and commits."""
    if s.get('finishedAt'):
        return 'Готово'
    if s.get('waiting'):
        return 'Ожидается решение пользователя'
    ts = s.get('tickets') or []
    for t in ts:
        if t.get('status') == 'repair':
            return 'Продолжить исправление и повторное ревью: ' + str(t.get('id'))
    for t in ts:
        if t.get('status') == 'review':
            return 'Завершить независимое ревью: ' + str(t.get('id'))
    for t in ts:
        if t.get('status') == 'in-progress':
            return 'Продолжить разработку: ' + str(t.get('id'))
    done_ids = {t.get('id') for t in ts if t.get('status') == 'done'}
    for t in sorted(ts, key=lambda x: (x.get('wave') or 999, str(x.get('id')))):
        if t.get('status') == 'pending' and set(t.get('blockedBy') or []).issubset(done_ids):
            return 'Начать следующую задачу: ' + str(t.get('id'))
    stages = s.get('stages') or []
    for st in stages:
        if st.get('status') == 'active':
            return 'Продолжить этап: ' + str(st.get('id'))
    return 'Проверить зависимости и требования'


def prompt_for(s, provider, step):
    skill = s.get('skillDir')
    candidate = Path(skill) / 'SKILL.md' if skill else None
    fallback = Path.home() / ('.agents/skills/autopilot/SKILL.md' if provider == 'codex'
                              else '.claude/skills/autopilot/SKILL.md')
    ref = str(candidate) if candidate and candidate.is_file() else (
        str(fallback) if fallback.is_file() else
        ('AGENTS.md' if provider == 'codex' else 'CLAUDE.md'))
    return ("Ты главный оркестратор Autopilot Quality PRO. Это новый автономный ход, НЕ новый прогон.\n"
            "Прочитай %s, .autopilot/state.js и соответствующую текущую phase; при необходимости используй "
            "phases/0-resume.md. Контекст прошлого CLI-вызова не сохраняется: истина в файлах проекта.\n"
            "Рабочая подсказка: %s.\n"
            "Продолжай незавершённую работу строго по правилам навыка. После исправления ОБЯЗАТЕЛЬНО "
            "проведи повторное независимое ревью; после принятия и фиксации переходи к следующему "
            "разрешённому зависимостями таску. Никакого пропуска тестов и подтверждений.\n"
            "На этом ходе выполни максимум одну законченную единицу работы (проверку/ремонт/ревью/таск/этап), "
            "вызови .autopilot/ap.py для регистрации РЕАЛЬНОГО перехода и заверши ход. "
            "Если работа занимает более одного хода, зафиксируй честное промежуточное событие только "
            "после проверяемого результата. Если нельзя продолжать безопасно — вызови ap.py ask action "
            "с причиной, не объявляй фиктивный успех. Не создавай новый init.\n"
            "Нельзя запускать runner.py изнутри агентного хода. Нельзя трогать runner.json/runner.lock. "
            "Запрещены деплой, публикация, секреты, опасные миграции, удаление данных без разрешения. "
            "Не изменяй рабочие файлы, если нужны отсутствующие разрешения/учётные данные — остановись.\n"
            "Шаг диспетчера %d.\n" % (ref, pending_action(s), step))


def command_for(provider, executable):
    if provider == 'codex':
        return [executable, 'exec', '--sandbox', 'workspace-write', '--ask-for-approval', 'never', '-']
    return [executable, '-p', '--permission-mode', 'acceptEdits', '--output-format', 'text']


def write_status(path, provider, status, step, note, started=None, changed=None):
    atomic_json(path, {'version': 1, 'provider': provider, 'status': status, 'step': step,
                       'updatedAt': iso(), 'startedAt': started or iso(), 'lastProgressAt': changed,
                       'note': note[:350]})


def git_changes(root):
    """Conservative: use git status as evidence of unrecorded work; no guessing outcome."""
    try:
        result = subprocess.run(['git', 'status', '--porcelain', '--untracked-files=no'],
                                cwd=str(root), capture_output=True, timeout=10)
        # Exclude the supervisor's own tracked files (only changed paths examined).
        return tuple(x for x in result.stdout.decode('utf-8', 'replace').splitlines()
                     if not x[3:].startswith('.autopilot/'))
    except (OSError, subprocess.TimeoutExpired):
        return ()


def terminate_child(proc):
    """Terminate a timed-out/aborted local CLI and its children, not another run."""
    if proc.poll() is not None:
        return
    if os.name == 'nt':
        subprocess.run(['taskkill', '/PID', str(proc.pid), '/T', '/F'],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    else:
        try:
            os.killpg(proc.pid, signal.SIGTERM)
        except ProcessLookupError:
            return
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            try:
                os.killpg(proc.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
    try:
        proc.wait(timeout=10)
    except subprocess.TimeoutExpired:
        pass


def completion_issues(state):
    """A snapshot being marked 'finished' is not proof that it was accepted."""
    incomplete = [t.get('id') for t in state.get('tickets') or [] if t.get('status') != 'done']
    issues = []
    if incomplete:
        issues.append('незавершённые таски: ' + ', '.join(map(str, incomplete)))
    gates = state.get('gates') or {}
    missing = [g for g in ('G1','G2','G3','G4') if not gates.get(g, {}).get('ok')]
    if missing:
        issues.append('не подтверждены гейты: ' + ', '.join(missing))
    tests = state.get('tests') or {}
    if tests.get('failed'):
        issues.append('красные тесты')
    return issues


def run(root, provider='codex', binary=None, max_steps=40, timeout=1800, idle_limit=300,
        poll=3, logger=print):
    ap = root / '.autopilot'
    statefile = ap / 'state.js'
    statusfile = ap / 'runner.json'
    if not ap.is_dir():
        raise RuntimeError('В проекте отсутствует .autopilot; начните прогон командой /autopilot')
    s = load(statefile)
    if s.get('finishedAt'):
        logger('Прогон уже завершён')
        return 0
    if s.get('waiting'):
        logger('Ожидается ответ пользователя: ' + str(s['waiting'].get('note') or ''))
        return 2
    exe = binary or shutil.which(provider)
    if not exe:
        raise RuntimeError('Не найден %s CLI. Установите и выполните вход, затем повторите.' % provider)
    if max_steps < 1 or timeout < 1 or idle_limit < 1:
        raise RuntimeError('Ограничения шагов и времени должны быть положительными')
    with Lock(ap / 'runner.lock'):
        started = iso()
        progress_at = s.get('updatedAt')
        write_status(statusfile, provider, 'running', 0, pending_action(s), started, progress_at)
        for step in range(1, max_steps + 1):
            before = state_key(s)
            changes_before = git_changes(root)
            hint = pending_action(s)
            write_status(statusfile, provider, 'running', step, hint, started, progress_at)
            logger('Ход %d/%d: %s' % (step, max_steps, hint))
            cmd = command_for(provider, exe)
            extra = {} if os.name == 'nt' else {'start_new_session': True}
            proc = subprocess.Popen(cmd, cwd=str(root), stdin=subprocess.PIPE,
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                    **extra)
            prompt = prompt_for(s, provider, step).encode('utf-8')
            try:
                proc.stdin.write(prompt)
                proc.stdin.close()
            except (OSError, BrokenPipeError):
                pass
            begin = time.monotonic()
            timed_out = False
            try:
                while proc.poll() is None:
                    if time.monotonic() - begin > timeout:
                        timed_out = True
                        terminate_child(proc)
                        break
                    time.sleep(min(poll, max(0.01, timeout - (time.monotonic() - begin))))
                    # Heartbeat is proof of a running supervisor only, not code progress.
                    elapsed = time.monotonic() - begin
                    write_status(statusfile, provider, 'stalled' if elapsed > idle_limit else 'running', step,
                                 ('Нет подтверждённого прогресса %d с; ожидание CLI' % int(elapsed)
                                  if elapsed > idle_limit else 'CLI выполняет работу: %s' % hint),
                                 started, progress_at)
            except KeyboardInterrupt:
                terminate_child(proc)
                msg = 'Остановлено оператором; состояние проекта требует проверки перед возобновлением'
                write_status(statusfile, provider, 'blocked', step, msg, started, progress_at)
                logger(msg)
                return 130
            if timed_out:
                msg = 'Время хода исчерпано, исход изменений неизвестен. Автоповтор запрещён; проверьте состояние.'
                write_status(statusfile, provider, 'blocked', step, msg, started, progress_at)
                logger(msg)
                return 3
            try:
                after = load(statefile)
            except RuntimeError as e:
                msg = 'Невозможно безопасно перечитать состояние: ' + str(e)
                write_status(statusfile, provider, 'blocked', step, msg, started, progress_at)
                logger(msg)
                return 3
            if proc.returncode != 0:
                msg = 'CLI завершился с кодом %s; состояние необходимо проверить вручную' % proc.returncode
                write_status(statusfile, provider, 'blocked', step, msg, started, progress_at)
                logger(msg)
                return 3
            if after.get('finishedAt'):
                issues = completion_issues(after)
                if issues:
                    msg = 'Финиш не подтверждён: ' + '; '.join(issues)
                    write_status(statusfile, provider, 'blocked', step, msg, started, after.get('updatedAt'))
                    logger(msg)
                    return 3
                write_status(statusfile, provider, 'done', step, 'Прогон завершён', started, after.get('updatedAt'))
                logger('Прогон завершён')
                return 0
            if after.get('waiting'):
                msg = 'Требуется действие пользователя: ' + str(after['waiting'].get('note') or '')
                write_status(statusfile, provider, 'waiting', step, msg, started, after.get('updatedAt'))
                logger(msg)
                return 2
            if after.get('dir') != s.get('dir') and not after.get('finishedAt'):
                msg = 'Папка прогона изменилась до завершения; проверьте новый init/reopen вручную'
                write_status(statusfile, provider, 'blocked', step, msg, started, progress_at)
                logger(msg)
                return 3
            if state_key(after) == before:
                changes_after = git_changes(root)
                msg = ('Есть незарегистрированные изменения проекта; повтор запрещён' if changes_after != changes_before
                       else 'Нет перехода state.js после успешного хода; повтор без результата запрещён')
                write_status(statusfile, provider, 'blocked', step, msg, started, progress_at)
                logger(msg)
                return 3
            progress_at = after.get('updatedAt')
            s = after
        msg = 'Достигнут лимит автономных ходов; новый запуск только после проверки статуса'
        write_status(statusfile, provider, 'paused', max_steps, msg, started, progress_at)
        logger(msg)
        return 4


def main(argv=None):
    parser = argparse.ArgumentParser(description='Автономный диспетчер Autopilot (Codex/Claude CLI)')
    parser.add_argument('--project', default='.', help='Папка проекта, где уже есть .autopilot/state.js')
    parser.add_argument('--provider', choices=['codex', 'claude'], default='codex')
    parser.add_argument('--max-steps', type=int, default=40)
    parser.add_argument('--timeout', type=int, default=1800, help='Максимум секунд на один ход')
    parser.add_argument('--binary', help='Явный путь к CLI, например C:/.../codex.cmd')
    opts = parser.parse_args(argv)
    try:
        return run(Path(opts.project).resolve(), provider=opts.provider, binary=opts.binary,
                   max_steps=opts.max_steps, timeout=opts.timeout)
    except (RuntimeError, OSError) as e:
        print('Autopilot: ' + str(e), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
