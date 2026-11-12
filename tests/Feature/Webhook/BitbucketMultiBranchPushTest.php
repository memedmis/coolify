<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $createApplication = fn (string $branch): Application => Application::create([
        'name' => "bitbucket-{$branch}",
        'git_repository' => 'https://bitbucket.org/test-org/test-repo',
        'git_branch' => $branch,
        'build_pack' => 'dockerfile',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->mainApplication = $createApplication('main');
    $this->developApplication = $createApplication('develop');
    $this->developApplication->update([
        'manual_webhook_secret_bitbucket' => $this->mainApplication->manual_webhook_secret_bitbucket,
    ]);
});

/**
 * @param  array<int, array<string, mixed>>  $changes
 */
function sendBitbucketMultiBranchPush(Application $application, array $changes): TestResponse
{
    $body = json_encode([
        'push' => ['changes' => $changes],
        'repository' => ['full_name' => 'test-org/test-repo'],
    ], JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.30',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Event-Key' => 'repo:push',
        'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_bitbucket),
    ], $body);
}

/**
 * @param  array<int, string>  $messages
 * @return array<string, mixed>
 */
function bitbucketBranchChange(string $branch, string $hash, array $messages = ['Update']): array
{
    return [
        'new' => ['type' => 'branch', 'name' => $branch, 'target' => ['hash' => $hash]],
        'commits' => array_map(fn (string $message): array => ['message' => $message], $messages),
    ];
}

function queuedBitbucketCommit(Application $application): ?string
{
    return ApplicationDeploymentQueue::query()->where('application_id', $application->id)->value('commit');
}

it('deploys every branch of a push with the commit of its own branch', function () {
    sendBitbucketMultiBranchPush($this->mainApplication, [
        bitbucketBranchChange('main', 'aaaaaaa1111111'),
        bitbucketBranchChange('develop', 'bbbbbbb2222222'),
    ])->assertOk();

    expect(queuedBitbucketCommit($this->mainApplication))->toBe('aaaaaaa1111111')
        ->and(queuedBitbucketCommit($this->developApplication))->toBe('bbbbbbb2222222');
});

it('skips only the branch whose commits contain a skip marker', function () {
    $response = sendBitbucketMultiBranchPush($this->mainApplication, [
        bitbucketBranchChange('main', 'aaaaaaa1111111', ['Docs [skip ci]']),
        bitbucketBranchChange('develop', 'bbbbbbb2222222', ['Feature']),
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('Skipping deployment')
        ->and(queuedBitbucketCommit($this->mainApplication))->toBeNull()
        ->and(queuedBitbucketCommit($this->developApplication))->toBe('bbbbbbb2222222');
});

it('deploys the pushed branches when the first change deletes a branch', function () {
    sendBitbucketMultiBranchPush($this->mainApplication, [
        ['new' => null, 'old' => ['type' => 'branch', 'name' => 'old-feature']],
        bitbucketBranchChange('develop', 'bbbbbbb2222222'),
    ])->assertOk();

    expect(queuedBitbucketCommit($this->mainApplication))->toBeNull()
        ->and(queuedBitbucketCommit($this->developApplication))->toBe('bbbbbbb2222222');
});
