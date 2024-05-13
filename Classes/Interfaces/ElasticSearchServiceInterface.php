<?php
namespace Slub\LisztBibliography\Interfaces;
use Illuminate\Support\Collection;


interface ElasticSearchServiceInterface
{
    public function init(): bool;

    public function getElasticInfo(): array;

    public function search(): Collection;
}
