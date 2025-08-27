<?php
namespace ParserPDF\Core;

class Context
{
    public string $filePath;
    public string $rawData = '';
    /** @var array<int,string> */
    public array $objects = [];
    /** @var int[] */
    public array $pages = [];
    /** @var array<int,array{w:float,h:float}> */
    public array $pageBoxes = [];
    /** fontObjNum => map */
    public array $fontMapsCache = [];

    /** @var array<int,array{page:int,text:string,x:float,y:float,font:?string,size:float,width:float,height:float}> */
    public array $fragments = [];
    /** @var array<int,array{page:int,x1:float,y1:float,x2:float,y2:float,orient:string,length:float}> */
    public array $lines = [];

    public array $options = [];
    public bool $debug = false;

    public function __construct(string $filePath, array $options)
    {
        $this->filePath = $filePath;
        $this->options  = $options;
        $this->debug    = (bool)($options['debug'] ?? false);
    }
}