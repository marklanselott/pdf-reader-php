<?php
namespace ParserPDF;

use ParserPDF\Core\Context;
use ParserPDF\Core\PdfLoader;
use ParserPDF\Core\ObjectIndexer;
use ParserPDF\Core\PageFinder;
use ParserPDF\Text\TextExtractor;
use ParserPDF\Graphics\LineExtractor;
use ParserPDF\Tables\TextTableDetector;
use ParserPDF\Tables\LineTableDetector;
use ParserPDF\Tables\TableMerger;
use ParserPDF\Assemble\ComponentBuilder;

class ParserPDF
{
    private Context $ctx;
    private bool $parsed = false;
    private array $tables = [];
    private array $components = [];

    public function __construct(string $filePath, array $options = [])
    {
        $this->ctx = new Context($filePath, $options);
    }

    public function parse(): void
    {
        if ($this->parsed) return;
        PdfLoader::load($this->ctx);
        ObjectIndexer::index($this->ctx);
        PageFinder::find($this->ctx);
        TextExtractor::extract($this->ctx);
        LineExtractor::extract($this->ctx);

        $textTables = TextTableDetector::detect($this->ctx);
        $lineTables = LineTableDetector::detect($this->ctx);

        $all = array_merge($textTables, $lineTables);
        $this->tables = TableMerger::merge($this->ctx, $all);

        $this->components = ComponentBuilder::build($this->ctx, $this->tables);
        $this->parsed = true;
    }

    public function toArray(): array
    {
        $this->parse();
        return $this->components;
    }

    public function toJSON(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}