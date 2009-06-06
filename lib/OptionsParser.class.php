<?php
// author: mark meves
class OptionsParserException extends Exception {
  
}

class OptionsParser {
  public function __construct($grammar){
    $this->grammar = $grammar;
  }
  public function parse(&$argv){
    $i = 1;
    $numToPop = 0;
    $tree = array();
    while (isset($argv[$i]) && preg_match('/^--([a-z0-9][-0-9a-z]*)(?:=(.+))?$/',$argv[$i],$m)) {
      if (!isset($this->grammar['options'][$m[1]])) {
        throw new OptionsParserException("Invalid option \"".$m[1]." -- expecting one of (".
          join(',',array_keys($this->grammar['options'])).
        ")");
      }
      if (isset($tree[$m[1]])) {
        throw new OptionsParserException("Can only specify '".$m[1]."' once."); // could be changed
      }
      if (isset($this->grammar['options'][$m[1]]['options'])) {
        $subOpts = $this->grammar['options'][$m[1]]['options'];
        if (!isset($m[2]) || !isset($subOpts[$m[2]])){
          throw new OptionsParserException('"'.$m[1].'" must be one of: ('.join(',',array_keys($subOpts)).')');
        }
      } elseif(isset($m[2])) {
        throw new OptionsParaserException('"'.$m[1].'" does not take any options');
      }
      $tree[$m[1]] = $m[2];
      $numToPop ++;
      $i ++;
    }
    if ($numToPop) {
      array_splice($argv,1,$numToPop);
    }
    return $tree;
  }
  public function describeOptions(){
    $lines = array();
    foreach($this->grammar['options'] as $name => $data){ 
      if (isset($data['options'])) {
        $lines []= sprintf('%-13s',' --'.$name).' '.join('|',array_keys($data['options']));
        foreach($data['options'] as $k=>$subOpt){ 
          $lines []= sprintf('%-13s','   - '.$k).' '.$subOpt['description'];
        }
      } else {
        $lines []= sprintf('%-13s','--'.$name).' '.$data['description'];
      }
    }
    return join("\n",$lines);
  }
  public function __toString(){
    $parts = array();
    foreach($this->grammar['options'] as $name=>$data ){
      $part = '[--'.$name;
      if ($data['options']) {
        $part .= '='.join('|',array_keys($data['options']));
      }
      $part .= ']';
      $parts []= $part;
    }
    return join(' ',$parts);
  }
  
}
