<?php

namespace App\Actions\System;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamDeployAction
{
    public function __construct(private readonly StreamConsoleAction $console) {}

    /**
     * @param  array<string, mixed>  $options  app:deploy
     * @param  array<string, mixed>  $context
     */
    public function handle(array $options, array $context = []): StreamedResponse
    {
        return $this->console->handle(
            fn (OutputInterface $output): int => Artisan::call('app:deploy', $options, $output),
            $context + ['task' => 'نشر', 'options' => $options],
        );
    }
}
