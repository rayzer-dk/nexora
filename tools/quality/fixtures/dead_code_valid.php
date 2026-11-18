<?php

final class NexoraDeadCodeValidFixture
{
    private int $value = 4;

    private function readValue(): int
    {
        return $this->value;
    }

    public function run(): int
    {
        return $this->readValue();
    }
}
