#!/usr/bin/env python3
"""Conservative controller-to-Twig context analyzer. Reports are advisory."""
import argparse
import re
from pathlib import Path
from collections import defaultdict

TAG = re.compile(r'({{\s*(.*?)\s*}}|{%\s*(.*?)\s*%})', re.S)
STRING = re.compile(r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*" """.strip(), re.S)
WORD = re.compile(r'(?<![.\w])([A-Za-z_]\w*)(?!\w)')
DATA = re.compile(r'\$data\s*\[\s*[\'"]([A-Za-z_]\w*)[\'"]\s*\]')
VIEW = re.compile(r'\$this\s*->\s*load\s*->\s*view\s*\(\s*[\'"]([\w/]+)[\'"]\s*,\s*\$data\b', re.S)
RENDER = re.compile(r'->render(?:View)?\s*\(\s*[\'"](@storefront/[\w/.-]+\.twig)[\'"]\s*,\s*\[', re.S)
IGNORED = set('and or not in is as if else elseif endif for endfor set endset do true false null none empty defined same starts with ends matches iterable even odd divisible by default loop app _self _context _charset this block parent include source range constant attribute min max only with extends endblock head body macro endmacro embed endembed'.split())

def twig_usage(text):
    text = re.sub(r'{#.*?#}', '', text, flags=re.S)
    locals_, reads, attributes = set(), set(), set()
    for token in TAG.finditer(text):
        expr = (token.group(2) or token.group(3) or '').strip()
        if re.match(r'^(?:block|endblock|extends|endextends|embed|endembed|use)\b', expr):
            continue
        for pattern in (r'^set\s+([A-Za-z_]\w*)\s*=', r'^for\s+([A-Za-z_]\w*)(?:\s*,\s*([A-Za-z_]\w*))?\s+in\b', r'^import\s+.+?\s+as\s+([A-Za-z_]\w*)'):
            found = re.search(pattern, expr, re.S)
            if found:
                locals_.update(x for x in found.groups() if x)
        locals_.update(re.findall(r'(?<!\w)([A-Za-z_]\w*)\s*=>', expr))
        if expr.startswith('macro '):
            found = re.match(r'macro\s+\w+\s*\(([^)]*)\)', expr)
            if found:
                locals_.update(re.findall(r'[A-Za-z_]\w*', found.group(1)))
        if re.match(r'^set\s+\w+\s*=', expr):
            expr = expr.split('=', 1)[1]
        elif re.match(r'^for\s+\w+', expr):
            expr = re.split(r'\s+in\s+', expr, maxsplit=1)[-1]
        elif re.match(r'^(macro|import|from)\s', expr):
            expr = ''
        expr = STRING.sub("''", expr)
        attributes.update((m.group(1), m.group(2)) for m in re.finditer(r'\b([A-Za-z_]\w*)\.([A-Za-z_]\w*)\b', expr))
        for found in WORD.finditer(expr):
            name = found.group(1)
            before, after = expr[:found.start()].rstrip(), expr[found.end():].lstrip()
            if name in IGNORED or before.endswith(('|', '.')) or after.startswith(('(', ':', '=>')):
                continue
            reads.add(name)
    return reads, locals_, attributes

def php_square_literal(code, start):
    """Extract balanced PHP [...] without executing the source."""
    if start >= len(code) or code[start] != '[':
        return None
    stack, quote, escaped = [], None, False
    for i in range(start, len(code)):
        ch = code[i]
        if quote:
            if escaped:
                escaped = False
            elif ch == '\\':
                escaped = True
            elif ch == quote:
                quote = None
        elif ch in "'\"":
            quote = ch
        elif ch in '([{':
            stack.append(ch)
        elif ch in ')]}':
            if not stack or '([{'.index(stack[-1]) != ')]}'.index(ch):
                return None
            stack.pop()
            if not stack:
                return code[start:i + 1]
    return None

def keys_of_literal(array):
    if not array or not array.startswith('[') or not array.endswith(']'):
        return {}
    content = array[1:-1]
    result, parts, start = {}, [], 0
    levels, quote, escaped = [], None, False
    for i, ch in enumerate(content):
        if quote:
            if escaped:
                escaped = False
            elif ch == '\\':
                escaped = True
            elif ch == quote:
                quote = None
        elif ch in "'\"":
            quote = ch
        elif ch in '([{':
            levels.append(ch)
        elif ch in ')]}':
            if levels:
                levels.pop()
        elif ch == ',' and not levels:
            parts.append(content[start:i])
            start = i + 1
    parts.append(content[start:])
    for item in parts:
        found = re.match(r'\s*[\'"]([A-Za-z_]\w*)[\'"]\s*=>\s*(.*)', item, re.S)
        if found:
            result[found.group(1)] = found.group(2).strip()
    return result

def contexts(root, mode):
    found = defaultdict(lambda: {'keys': set(), 'shapes': defaultdict(set)})
    if mode == 'codecart':
        for area in ('admin', 'catalog'):
            language_keys = set()
            for locale_file in (root / 'upload' / area / 'language').rglob('*.php'):
                language_keys.update(re.findall(r"\$_\s*\[\s*['\"]([A-Za-z_]\w*)['\"]\s*\]", locale_file.read_text(encoding='utf-8', errors='replace')))
            folder = root / 'upload' / area / 'controller'
            if not folder.exists():
                continue
            for php in folder.rglob('*.php'):
                source = php.read_text(encoding='utf-8', errors='replace')
                keys = set(DATA.findall(source)) | language_keys
                for route in VIEW.findall(source):
                    found[(area, route)]['keys'].update(keys)
    else:
        for php in (root / 'src').rglob('*.php'):
            source = php.read_text(encoding='utf-8', errors='replace')
            for match in RENDER.finditer(source):
                name = match.group(1)[len('@storefront/'):]
                array = php_square_literal(source, match.end() - 1)
                if not array:
                    continue
                literal = keys_of_literal(array)
                record = found[('storefront', name)]
                record['keys'].update(literal)
                for key, value in literal.items():
                    if value.startswith('['):
                        nested = php_square_literal(value, 0)
                        if nested == value:
                            record['shapes'][key].update(keys_of_literal(nested))
    return found

def analyze(source, record):
    reads, locals_, attrs = twig_usage(source)
    issues = [('unknown-context', x) for x in sorted(reads - locals_ - record['keys'] - {'loop', 'app', '_self'})]
    issues += [('unused-local', x) for x in sorted(locals_ - reads)]
    issues += [('unknown-literal-key', root + '.' + key) for root, key in sorted(attrs)
               if root in record['shapes'] and key not in record['shapes'][root]]
    return issues

def self_test():
    record = {'keys': {'title', 'items', 'profile'}, 'shapes': {'profile': {'name'}}}
    assert analyze("{% set greeting = 'Hello' %}{{ greeting }} {{ title }} {% for item in items %}{{ item }}{% endfor %}", record) == []
    findings = analyze("{{ titele }} {{ profile.nam }} {% set unused = 1 %}", record)
    assert ('unknown-context', 'titele') in findings, findings
    assert ('unknown-literal-key', 'profile.nam') in findings, findings
    assert ('unused-local', 'unused') in findings, findings
    php = "return $this->render('@storefront/home.html.twig', ['title' => 'ok', 'profile' => ['name' => 'ok']]);"
    match = RENDER.search(php)
    assert match and keys_of_literal(php_square_literal(php, match.end() - 1))['title'] == "'ok'"
    print('PASS: known and unknown roots, unused locals, nested literal keys, PHP render arrays')

def audit(root, mode, report):
    lookup = contexts(root, mode)
    templates = ([(root / 'upload/admin/view/template', 'admin'),
                  (root / 'upload/catalog/view/theme/default/template', 'catalog'),
                  (root / 'upload/catalog/view/theme/codecart/template', 'catalog')]
                 if mode == 'codecart' else [(root / 'themes/default/templates', 'storefront')])
    scanned = matched = 0
    lines = []
    inherited_keys, inherited_reads = set(), set()
    if mode == 'nexora':
        base_template = root / 'themes/default/templates/base.html.twig'
        if base_template.is_file():
            inherited_reads, inherited_keys, _ = twig_usage(base_template.read_text(encoding='utf-8', errors='replace'))
    for base, scope in templates:
        if not base.exists():
            continue
        for path in base.rglob('*.twig'):
            scanned += 1
            name = path.relative_to(base).as_posix()
            if mode == 'codecart':
                name = name[:-len('.twig')]
            record = lookup.get((scope, name))
            if record is None:
                continue
            matched += 1
            source = path.read_text(encoding='utf-8', errors='replace')
            effective = record
            if mode == 'nexora' and '{% extends' in source:
                effective = {'keys': record['keys'] | inherited_keys, 'shapes': record['shapes']}
            for category, identifier in analyze(source, effective):
                if category == 'unused-local' and mode == 'nexora' and '{% extends' in source and identifier in inherited_reads:
                    continue
                lines.append(f'{path.relative_to(root)}: {category}: {identifier}')
    header = [f'Templates scanned: {scanned}; direct controller mappings: {matched}; findings: {len(lines)}',
              'ADVISORY: dynamic data, OCMOD, Events, Twig inheritance, Symfony globals and includes may cause false positives.',
              'Only direct, statically recognizable PHP-to-Twig contexts are examined.', '']
    report.parent.mkdir(parents=True, exist_ok=True)
    report.write_text('\n'.join(header + lines) + '\n', encoding='utf-8')
    print('\n'.join(header + lines[:80]))

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path('.'))
    parser.add_argument('--mode', choices=['codecart', 'nexora'], default='codecart')
    parser.add_argument('--report', type=Path, default=Path('build/twig-context-report.txt'))
    parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()
    self_test() if args.self_test else audit(args.root, args.mode, args.report)
