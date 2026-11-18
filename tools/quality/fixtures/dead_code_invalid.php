<?php

function nexoraDeadCodeInvalidFixture(): void
{
    $forgotten = 'never read';
}

final class NexoraDeadCodeInvalidFixture
{
    private string $unusedProperty = 'unused';

    private function unusedMethod(): void
    {
    }

    public function run(): void
    {
    }
}
