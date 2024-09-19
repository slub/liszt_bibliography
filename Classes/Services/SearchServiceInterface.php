<?php

namespace Slub\LisztBibliography\Services;

use Illuminate\Support\Collection;

interface SearchServiceInterface
{
    public function search(string $searchTerm): SearchServiceInterface;

    public function get(string $id): SearchServiceInterface;

    public function from(int $from): SearchServiceInterface;

    public function limit(int $size): SearchServiceInterface;

    public function send(): Collection;

    public function count(): int;
}
