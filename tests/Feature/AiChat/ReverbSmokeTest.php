<?php

namespace Tests\Feature\AiChat;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\SubscribesStudents;
use Tests\TestCase;

class ReverbSmokeTest extends TestCase
{
    use DatabaseMigrations, SubscribesStudents;

    public function test_real_reverb_socket_private_subscription_and_complete_response_delivery(): void
    {
        $student = $this->subscribedStudent();
        Sanctum::actingAs($student);
        $conversation = AiConversation::factory()->for($student)->create();
        $message = AiMessage::factory()->create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'Complete smoke test response']);
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $key = 'smoke-key';
        $secret = 'smoke-secret';
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => $key, 'secret' => $secret, 'app_id' => 'smoke-app',
            'options' => ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'useTLS' => false],
        ]]);
        require base_path('routes/channels.php');

        $server = new Process([PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', '--port='.$port, '--no-interaction'], base_path(), [
            'APP_ENV' => 'testing', 'REVERB_APP_ID' => 'smoke-app', 'REVERB_APP_KEY' => $key,
            'REVERB_APP_SECRET' => $secret, 'REVERB_ALLOWED_ORIGINS' => '*', 'CACHE_STORE' => 'array',
        ]);
        $server->setTimeout(10);
        $input = new InputStream;
        $script = <<<'JS'
import readline from 'node:readline';
const socket = new WebSocket(process.argv[1]);
const timeout = setTimeout(() => process.exit(2), 8000);
socket.addEventListener('error', () => process.exit(3));
socket.addEventListener('message', ({data}) => {
    const event = JSON.parse(data);
    if (event.event === 'pusher:connection_established') {
        console.log(JSON.parse(event.data).socket_id);
    } else if (event.event === 'pusher_internal:subscription_succeeded') {
        console.log('subscribed');
    } else if (event.event === 'chat.response.completed') {
        const payload = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
        console.log(JSON.stringify(payload));
        clearTimeout(timeout);
        socket.close();
        process.exit(0);
    } else if (event.event === 'pusher:error') {
        process.exit(4);
    }
});
readline.createInterface({input: process.stdin}).on('line', line => socket.send(line));
JS;
        $client = new Process(['node', '--input-type=module', '-e', $script, 'ws://127.0.0.1:'.$port.'/app/'.$key.'?protocol=7&client=smoke&version=1.0'], base_path());
        $client->setInput($input);
        $client->setTimeout(10);
        try {
            $server->start();
            $this->assertTrue($server->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'Starting server')));
            $client->start();
            $this->assertTrue($client->waitUntil(fn (string $type, string $output): bool => preg_match('/\d+\.\d+/', $output) === 1));
            $socketId = trim($client->getOutput());
            $channel = 'private-student.ai.conversation.'.$conversation->id;
            $authorization = $this->postJson('/api/student/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => $channel])->assertOk()->json('auth');
            $this->postJson('/api/student/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => 'private-student.ai.conversation.999999'])->assertForbidden();
            $input->write(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $authorization]])."\n");
            $this->assertTrue($client->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'subscribed')));
            Broadcast::connection('reverb')->broadcast([$channel], 'chat.response.completed', [
                'conversation_id' => $conversation->id, 'message_id' => $message->id,
                'status' => 'completed', 'content' => $message->content,
            ]);
            $client->wait();
            $this->assertSame(0, $client->getExitCode(), $client->getErrorOutput());
            $this->assertStringContainsString('Complete smoke test response', $client->getOutput());
            $this->assertStringContainsString('"message_id":'.$message->id, $client->getOutput());
        } finally {
            $input->close();
            $client->stop(0);
            $server->stop(0);
        }
    }
}
