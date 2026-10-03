<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Security\SecretVault;
use Commerce\Modules\SupportChat\Application\SupportChatService;
use Commerce\Modules\SupportChat\Application\SupportChatSettings;
use Commerce\Modules\SupportChat\Infrastructure\TelegramBotClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SupportChatServiceTest extends TestCase
{
    private const TOKEN = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const GROUP = '-1001234567890';

    private Connection $db;
    private SupportChatService $chat;

    /** @var list<array{method:string,payload:array<string,mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_support_chat_settings (store_id INTEGER PRIMARY KEY, enabled INTEGER, site_chat_enabled INTEGER, direct_enabled INTEGER, bot_token_enc TEXT, bot_username TEXT, group_chat_id TEXT, webhook_secret TEXT, welcome_text TEXT, offline_text TEXT, seen_chats TEXT, updated_at TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_support_thread (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER, channel TEXT, public_token TEXT, telegram_user_id INTEGER, tg_topic_id INTEGER, customer_name TEXT, customer_contact TEXT, customer_user_id INTEGER, source_url TEXT, status TEXT, created_at TEXT, last_message_at TEXT)');
        $this->db->executeStatement('CREATE TABLE mc_support_message (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER, direction TEXT, body TEXT, tg_message_id INTEGER, tg_update_id INTEGER UNIQUE, created_at TEXT)');

        $settings = new SupportChatSettings($this->db, new SecretVault(str_repeat('s', 32)));
        $settings->save(1, ['enabled' => 1, 'site_chat_enabled' => 1, 'direct_enabled' => 1, 'bot_token' => self::TOKEN, 'group_chat_id' => self::GROUP]);

        $topic = 100;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$topic): MockResponse {
            $name = basename($url);
            $payload = json_decode((string) ($options['body'] ?? '{}'), true) ?: [];
            $this->calls[] = ['method' => $name, 'payload' => $payload];
            $result = match ($name) {
                'createForumTopic' => ['message_thread_id' => ++$topic],
                'sendMessage', 'copyMessage' => ['message_id' => 9000 + count($this->calls)],
                default => [],
            };

            return new MockResponse(json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
        });
        $this->chat = new SupportChatService($this->db, $settings, new TelegramBotClient($client), new NullLogger());
    }

    public function testVisitorMessageOpensATopicAndIsForwardedIntoIt(): void
    {
        $thread = $this->chat->createWebThread(1, 'Anna', 'anna@example.test', null, 'https://shop.test/p');
        $result = $this->chat->postFromVisitor(1, $thread, 'Hello there');

        self::assertTrue($result['delivered']);
        self::assertSame(['createForumTopic', 'sendMessage', 'sendMessage'], array_column($this->calls, 'method'));
        self::assertSame(self::GROUP, $this->calls[0]['payload']['chat_id']);
        self::assertSame(101, $this->calls[2]['payload']['message_thread_id']);
        self::assertSame('Hello there', $this->calls[2]['payload']['text']);

        // The second message reuses the topic.
        $this->chat->postFromVisitor(1, $this->chat->threadByToken(1, (string) $thread['public_token']) ?? [], 'Again');
        self::assertSame(['createForumTopic', 'sendMessage', 'sendMessage', 'sendMessage'], array_column($this->calls, 'method'));
    }

    public function testStaffReplyInsideTheTopicReachesOnlyThatWebVisitor(): void
    {
        $anna = $this->chat->createWebThread(1, 'Anna', '', null, '');
        $ben = $this->chat->createWebThread(1, 'Ben', '', null, '');
        $this->chat->postFromVisitor(1, $anna, 'Question A');
        $this->chat->postFromVisitor(1, $ben, 'Question B');

        $this->chat->handleUpdate(1, ['update_id' => 1, 'message' => ['message_id' => 5, 'is_topic_message' => true, 'message_thread_id' => 101, 'chat' => ['id' => (int) self::GROUP, 'type' => 'supergroup', 'is_forum' => true, 'title' => 'Staff'], 'from' => ['id' => 42, 'is_bot' => false], 'text' => 'Answer for Anna']]);

        $forAnna = $this->chat->messages((int) $anna['id']);
        $forBen = $this->chat->messages((int) $ben['id']);
        self::assertSame(['in', 'out'], array_column($forAnna, 'dir'));
        self::assertSame('Answer for Anna', $forAnna[1]['body']);
        self::assertSame(['in'], array_column($forBen, 'dir'));
    }

    public function testCustomerWritingToTheBotGetsATopicAndTheAnswerComesBackPrivately(): void
    {
        $private = ['update_id' => 10, 'message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Olga', 'username' => 'olga'], 'text' => 'Is it in stock?']];
        $this->chat->handleUpdate(1, $private);
        self::assertSame(['createForumTopic', 'sendMessage', 'sendMessage'], array_column($this->calls, 'method'));

        $this->calls = [];
        $this->chat->handleUpdate(1, ['update_id' => 11, 'message' => ['message_id' => 6, 'is_topic_message' => true, 'message_thread_id' => 101, 'chat' => ['id' => (int) self::GROUP, 'type' => 'supergroup'], 'from' => ['id' => 42, 'is_bot' => false], 'text' => 'Yes, it is']]);
        self::assertSame(['sendMessage'], array_column($this->calls, 'method'));
        self::assertSame('555', $this->calls[0]['payload']['chat_id']);
        self::assertSame('Yes, it is', $this->calls[0]['payload']['text']);
    }

    public function testTheSameTelegramUpdateIsStoredOnce(): void
    {
        $update = ['update_id' => 20, 'message' => ['message_id' => 1, 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Ivan'], 'text' => 'Hi']];
        $this->chat->handleUpdate(1, $update);
        $this->chat->handleUpdate(1, $update);

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM mc_support_message'));
    }

    public function testCloseCommandAndNoiseInTheGroupAreHandled(): void
    {
        $thread = $this->chat->createWebThread(1, 'Anna', '', null, '');
        $this->chat->postFromVisitor(1, $thread, 'Hi');
        $topicMessage = static fn (int $id, string $text, bool $topic = true): array => ['update_id' => $id, 'message' => ['message_id' => $id, 'is_topic_message' => $topic, 'message_thread_id' => 101, 'chat' => ['id' => (int) self::GROUP, 'type' => 'supergroup'], 'from' => ['id' => 42, 'is_bot' => false], 'text' => $text]];

        $this->chat->handleUpdate(1, $topicMessage(30, 'not a topic message', false));
        self::assertCount(1, $this->chat->messages((int) $thread['id']));

        $this->chat->handleUpdate(1, $topicMessage(31, '/close'));
        self::assertSame('closed', $this->db->fetchOne('SELECT status FROM mc_support_thread WHERE id=?', [$thread['id']]));
        self::assertCount(1, $this->chat->messages((int) $thread['id']));
    }
}
