<?php

namespace Tests\Feature;

use App\Models\BufferConnection;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\BufferService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BufferIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Editor', 'web');
    }

    public function test_buffer_client_uses_the_configured_ca_bundle(): void
    {
        config()->set('social.buffer.ca_bundle', 'C:/certificates/cacert.pem');

        $service = new BufferService;
        $property = new ReflectionProperty($service, 'http');
        $client = $property->getValue($service);

        $this->assertSame('C:/certificates/cacert.pem', $client->getConfig('verify'));
    }

    #[DataProvider('instagramContentTypes')]
    public function test_instagram_publish_sends_required_buffer_post_type(string $contentType, string $expectedType): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'createPost' => [
                        '__typename' => 'PostActionSuccess',
                        'post' => ['id' => 'buffer-post-1', 'externalLink' => null, 'status' => 'buffer'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $service = new BufferService;
        (new ReflectionProperty($service, 'http'))->setValue($service, new Client(['handler' => $stack]));

        $connection = new BufferConnection(['api_key' => 'test-key']);
        $account = new SocialAccount(['platform' => 'instagram', 'internal_id' => 'channel-1']);
        $post = new Post([
            'title' => 'Konten Instagram',
            'content_type' => $contentType,
            'caption' => 'Caption',
        ]);
        $platform = new PostPlatform(['platform' => 'instagram']);

        $service->publish($connection, $account, $post, $platform, []);

        $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expectedType, $payload['variables']['input']['metadata']['instagram']['type']);
        $this->assertSame($expectedType !== 'story', $payload['variables']['input']['metadata']['instagram']['shouldShareToFeed']);
        $this->assertArrayNotHasKey('geolocation', $payload['variables']['input']['metadata']['instagram']);
    }

    /** @return array<string, array{string, string}> */
    public static function instagramContentTypes(): array
    {
        return [
            'photo' => ['photo', 'post'],
            'carousel' => ['carousel', 'post'],
            'video' => ['video', 'reel'],
            'story' => ['story', 'story'],
        ];
    }

    public function test_instagram_publish_sends_the_configured_location_to_buffer(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'createPost' => [
                        '__typename' => 'PostActionSuccess',
                        'post' => ['id' => 'buffer-post-location', 'externalLink' => null, 'status' => 'buffer'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $service = new BufferService;
        (new ReflectionProperty($service, 'http'))->setValue($service, new Client(['handler' => $stack]));

        $service->publish(
            new BufferConnection(['api_key' => 'test-key']),
            new SocialAccount(['platform' => 'instagram', 'internal_id' => 'channel-1']),
            new Post(['title' => 'Lokasi Instagram', 'content_type' => 'photo', 'caption' => 'Caption']),
            new PostPlatform(['platform' => 'instagram', 'advanced_settings' => ['location' => '  Jakarta, Indonesia  ']]),
            [],
        );

        $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['text' => 'Jakarta, Indonesia'],
            $payload['variables']['input']['metadata']['instagram']['geolocation'],
        );
    }

    public function test_tiktok_video_publish_sends_metadata_as_an_object(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [
                    'createPost' => [
                        '__typename' => 'PostActionSuccess',
                        'post' => ['id' => 'buffer-post-1', 'externalLink' => null, 'status' => 'buffer'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $service = new BufferService;
        (new ReflectionProperty($service, 'http'))->setValue($service, new Client(['handler' => $stack]));

        $service->publish(
            new BufferConnection(['api_key' => 'test-key']),
            new SocialAccount(['platform' => 'tiktok', 'internal_id' => 'channel-1']),
            new Post(['title' => 'Video TikTok', 'content_type' => 'video', 'caption' => 'Caption']),
            new PostPlatform(['platform' => 'tiktok']),
            [],
        );

        $body = (string) $history[0]['request']->getBody();
        $this->assertStringContainsString('"metadata":{"tiktok":{}}', $body);
    }

    public function test_user_can_only_sync_their_own_buffer_connection_without_exposing_key(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');
        $connection = BufferConnection::factory()->for($user)->create([
            'api_key' => 'secret-buffer-key',
            'account_name' => 'Pegawai Buffer',
        ]);

        $buffer = Mockery::mock(BufferService::class);
        $buffer->shouldReceive('syncChannels')->once()->withArgs(
            fn (BufferConnection $argument): bool => $argument->is($connection),
        )->andReturnUsing(function () use ($user, $connection): array {
            $account = SocialAccount::query()->create([
                'platform' => 'instagram',
                'internal_id' => 'buffer-channel-1',
                'account_name' => 'SocialSync Instagram',
                'handle' => '@socialsync',
                'status' => 'connected',
                'metadata' => ['provider' => 'buffer'],
                'connected_by' => $user->id,
            ]);
            $connection->update(['last_synced_at' => now()]);

            return [$account];
        });
        $this->app->instance(BufferService::class, $buffer);

        $response = $this->actingAs($user)->postJson('/api/buffer/sync')->assertOk();

        $response->assertJsonPath('bufferConnection.channelCount', 1)
            ->assertJsonPath('accounts.0.accountName', 'SocialSync Instagram')
            ->assertJsonMissing(['api_key' => 'secret-buffer-key'])
            ->assertJsonMissing(['apiKey' => 'secret-buffer-key']);
        $this->assertStringNotContainsString('secret-buffer-key', $response->getContent());
    }

    public function test_sync_rejects_more_than_three_supported_active_channels(): void
    {
        config()->set('social.buffer.max_channels', 3);
        $connection = BufferConnection::factory()->create();
        $channels = collect(['instagram', 'tiktok', 'facebook', 'instagram'])
            ->map(fn (string $service, int $index): array => [
                'id' => 'channel-'.$index,
                'name' => 'channel-'.$index,
                'displayName' => 'Channel '.$index,
                'service' => $service,
                'isDisconnected' => false,
                'isLocked' => false,
            ])->all();

        $buffer = Mockery::mock(BufferService::class)->makePartial();
        $buffer->shouldReceive('channels')->once()->andReturn($channels);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maksimal 3 channel');

        $buffer->syncChannels($connection);
    }

    public function test_bootstrap_exposes_only_the_signed_in_users_buffer_channels(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');
        $other = User::factory()->create(['active' => true]);
        $other->assignRole('Editor');
        BufferConnection::factory()->for($user)->create();

        foreach ([[$user, 'own-channel'], [$other, 'other-channel']] as [$owner, $channelId]) {
            SocialAccount::query()->create([
                'platform' => 'facebook',
                'internal_id' => $channelId,
                'account_name' => $channelId,
                'status' => 'connected',
                'metadata' => ['provider' => 'buffer'],
                'connected_by' => $owner->id,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/bootstrap')->assertOk();

        $response->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.accountName', 'own-channel')
            ->assertJsonPath('bufferConnection.channelCount', 1);
        $this->assertArrayNotHasKey('api_key', $response->json('bufferConnection'));
    }
}
