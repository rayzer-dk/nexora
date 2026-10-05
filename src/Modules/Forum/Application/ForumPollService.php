<?php

declare(strict_types=1);

namespace Commerce\Modules\Forum\Application;

use Commerce\Core\I18n\CanonicalUiText;
use Doctrine\DBAL\Connection;

final readonly class ForumPollService
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param list<string> $options */
    public function create(int $topicId, string $question, array $options, bool $multiple): void
    {
        $question = trim(mb_substr(strip_tags($question), 0, 240, 'UTF-8'));
        $clean = [];
        foreach ($options as $option) {
            $label = trim(mb_substr(strip_tags((string) $option), 0, 190, 'UTF-8'));
            if ($label !== '') {
                $clean[mb_strtolower($label, 'UTF-8')] = $label;
            }
        }
        $clean = array_slice(array_values($clean), 0, 10);
        if ($question === '' || count($clean) < 2) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.poll_invalid'));
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->connection->insert('mc_forum_poll', ['topic_id' => $topicId, 'question' => $question, 'is_multiple' => $multiple ? 1 : 0, 'created_at' => $now]);
        $pollId = (int) $this->connection->lastInsertId();
        foreach ($clean as $i => $label) {
            $this->connection->insert('mc_forum_poll_option', ['poll_id' => $pollId, 'label' => $label, 'sort_order' => $i]);
        }
    }

    /** @return array{id:int,question:string,multiple:bool,total:int,voters:int,voted:bool,options:list<array{id:int,label:string,votes:int,percent:int,mine:bool}>}|null */
    public function forTopic(int $topicId, ?int $customerId): ?array
    {
        $poll = $this->connection->fetchAssociative('SELECT id,question,is_multiple FROM mc_forum_poll WHERE topic_id=?', [$topicId]);
        if (!is_array($poll)) {
            return null;
        }
        $pollId = (int) $poll['id'];
        $options = $this->connection->fetchAllAssociative(
            'SELECT o.id,o.label,(SELECT COUNT(*) FROM mc_forum_poll_vote v WHERE v.option_id=o.id) AS votes
             FROM mc_forum_poll_option o WHERE o.poll_id=? ORDER BY o.sort_order,o.id',
            [$pollId],
        );
        $mine = $customerId === null ? [] : array_map('intval', $this->connection->fetchFirstColumn('SELECT option_id FROM mc_forum_poll_vote WHERE poll_id=? AND customer_id=?', [$pollId, $customerId]));
        $total = array_sum(array_map(static fn (array $o): int => (int) $o['votes'], $options));
        $voters = (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT customer_id) FROM mc_forum_poll_vote WHERE poll_id=?', [$pollId]);
        $rows = [];
        foreach ($options as $o) {
            $votes = (int) $o['votes'];
            $rows[] = ['id' => (int) $o['id'], 'label' => (string) $o['label'], 'votes' => $votes, 'percent' => $total > 0 ? (int) round($votes * 100 / $total) : 0, 'mine' => in_array((int) $o['id'], $mine, true)];
        }

        return ['id' => $pollId, 'question' => (string) $poll['question'], 'multiple' => (int) $poll['is_multiple'] === 1, 'total' => $total, 'voters' => $voters, 'voted' => $mine !== [], 'options' => $rows];
    }

    /** @param list<int> $optionIds */
    public function vote(int $pollId, int $customerId, array $optionIds): void
    {
        $poll = $this->connection->fetchAssociative('SELECT id,is_multiple FROM mc_forum_poll WHERE id=?', [$pollId]);
        if (!is_array($poll)) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.poll_invalid'));
        }
        $valid = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM mc_forum_poll_option WHERE poll_id=?', [$pollId]));
        $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds), static fn (int $id): bool => in_array($id, $valid, true))));
        if ($optionIds === [] || ((int) $poll['is_multiple'] === 0 && count($optionIds) > 1)) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.poll_choose'));
        }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM mc_forum_poll_vote WHERE poll_id=? AND customer_id=?', [$pollId, $customerId]) > 0) {
            throw new \DomainException(CanonicalUiText::get('forum.runtime.poll_voted'));
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        foreach ($optionIds as $id) {
            $this->connection->executeStatement('INSERT IGNORE INTO mc_forum_poll_vote(poll_id,option_id,customer_id,created_at) VALUES (?,?,?,?)', [$pollId, $id, $customerId, $now]);
        }
    }
}
