<?php

/**
* Output to stdout a DOT file showing the class inheiritance hierarchy
* for a set of classes.  You define the set of classes you care about in 
* terms of a list of one or more folders they will appear under,
* and in terms of list of one or more possible filename patterns
* that indicate which files under these folders classes may be defined.
*
* The DOT file is written to STDOUT.
*
* Additionally, you can define a set of folder names never to descend into,
* for example, .svn folders or log folders
*
* This is intended to be a standalone file completely divorced from any framework.
*
*
* Usage: 
*  Please try running this from the command line.  It should hopefully give a general indication
*  of the two steps involved.   1) is create a paramters file (and possibly edit it)  
*  2) is generate a doc file.
* 
* 
* Issues: 
*  This may render a class without showing its base class, if that base class
*  is defined outside of the set of folders to search
* 
*  This relies upon the unix utilities "find" and "grep" specifically
*  the BSD (GNU?) versions found on Mac OSX.  Your mileage may vary.
*
*  the class name must appear on the same line, right after the class keyword.  (''spot 1'')
*  This could change if we end up rewriting this to use the php tokenize function.
*
*
* 
* @author mark meves
* version 0.01 created on Fri, Aug 22, 2008
*/

class ClassGrapher {
  
  private $files, $finds; 
  
  // ----------- Public Methods ----------------
  public function __construct(){
    $this->outputStream = new StdLogger();
    $name = '[a-zA-Z_][a-zA-Z_0-9]*';
    $sp   = '[[:space:]]+';
    $sp2  = '[[:space:]]*';    
    $this->regex = "/\\bclass $sp ($name) (?: $sp extends $sp ($name) )?   
    (?: $sp implements $sp ($name (?:$sp2,$sp2 $name)*))? $sp2 {     /x";
  }
  
  /** 
  * call this after you have called setParams.  affected by ''spot 2''
  */
  public function writeDotToStdOut(){    
    if ( !isset( $this->structure) ) { 
      $this->getStructure(); 
      //var_export( $this->structure );
      //die("pad thai");
    }
    $this->writeDotFile();
  }

  /**
  * we reveal this to callers so that other clients can decide what to do with the data.
  * if you change any of this see ''spot 2''
  * post: structure is set
  */
  public function getStructure() {
    if ( !isset($this->structure) ) {
      $this->populateStructure();
    }
    return $this->structure;
  }
  
  public function setParams( $args ){
    $this->structure = null;
    $this->files = null;
    $this->finds = null;
    $this->folders = null;
    $this->baseClasses = null;
    // catch bad directories early -- if we don't we can waste a lot of the user's time
    // chop trailing slash for the find command, which adds its own slashes to its output    
    foreach( array_keys($args['search folders']) as $i){
      $dir = $args['search folders'][$i];
      if ('/'==$dir[strlen($dir)-1]){ 
        $dir = substr( $dir, 0, -1 ); 
        $args['search folders'][$i] = $dir;
      } 
      if (!is_dir( $dir )){
        throw new ArgumentException( "is not a directory: \"$dir\"");
      }
    }
    $this->includeDirs       = $args['search folders'];
    $this->baseClasses       = $args['base classes'];
    $this->extensions        = $args['filename patterns to include'];
    $this->skipDirs          = $args['directory names to ignore'];
  }
  
  // for debugging from command line
  public function getSearchFiles(){
    if (null==$this->finds){
      $this->generateFindCommands();
    }
    if (null==$this->files) {
      $this->populateFilesList();
    }
    return $this->files;
  }
  
  // for debugging from command line
  public function getFindCommands(){
    if (null===$this->finds){
      $this->generateFindCommands();
    }
    return array_map( create_function('$x','return $x["find"];'), $this->finds );
  }
  
  // -------------- Protected Methods -------------------------
  
  private function stdOut( $msg ){
    $this->outputStream->out( $msg );
  }
  
  private function stdErr( $msg ){
    $this->outputStream->err( $msg );
  }
  
  private function populateFilesList(){
    if ( !isset( $this->finds )) {
      $this->generateFindCommands();    
    }
    $this->files = array();
    foreach( $this->finds as $find ){
      $result = trim(shell_exec( $find['find'] ));
      $newFiles = split("\n",$result);
      array_splice( $this->files, count($this->files), 0, $newFiles );
    }
    $this->files = array_unique( $this->files );
  }  

  /**
  * a simple example:  deeply find all *.php and *.js files, but don't descend into folders 
  * called "vendor" or "evil"
  * find testdir -not \( -type d \( -name vendor -o -name evil \) -prune  \) -a \( -name "*.php" -o -name "*.js" \)
  * 
  */
  private function generateFindCommands(){
    $this->finds = array();
    $andGroup = array();
    if (count($this->skipDirs)){
      $skipDirStr = join(' -o ',array_map(create_function('$x','return \'-name \'.$x;'), $this->skipDirs));      
      $andGroup[]= '-not \( -type d \( '.$skipDirStr.' \) -prune \)';
    }
    if (count($this->extensions)){
      $extStr = join(' -o ',array_map(create_function('$x','return \'-name "\'.$x.\'"\';'), $this->extensions));    
      $andGroup[]= '\( '.$extStr.' \)';
    }
    $findPostfix = join(' -a ',$andGroup);
    foreach( $this->includeDirs as $dir ){
      $this->finds []= array(
        'directory' => $dir,
        'find'      => "find $dir $findPostfix | ".
          "xargs grep -l \"\\<class[[:space:]]\\+[a-zA-Z_]\""  // ''spot 1''
      );
      // we started building this out before we realized that unix grep doesn't support multiline matching.
      // however usually developers don't break class signatures across lines (?) so 
      // for cases where the developers are sure that they appear on the same line, performance could be improved
      // by using the grep utility rather than opening each file with the php tokenizer
      //"\<class[[:space:]]\+[a-zA-Z][A-Za-z_0-9]*[[:space:]]\+\(extends[[:space:]]\+[a-zA-Z_][a-zA-Z_-9]*\)\?
      //\([[:space:]]+implements[[:space:]]\+\)\?"
      // however, we do require that the class name follows the class with no interceding newline.
      // this allows us to skip the many php files that render html with CSS class specifications.        
    }
  }

  private function populateStructure(){
    if (!isset($this->files)){
      $this->populateFilesList(); 
    }
    $this->structure = array(
      'classes'         => array(), // hash class is key, value is 1 for now
      'ascendants'      => array(), // hash child => parent
      'defined_in_file' => array(), // hash class => filename
      'interfaces'      => array(), // like classes above
      'implements'      => array()  // hash class => array( names )
    );
    foreach( $this->files as $file ){
      $this->getClassInfoInFile( $file );
    }
    $this->pruneStructure();
  }
  
  
  private function populateWithDescendants( $class, &$whitelist ){
    $descendants = array_keys( $this->structure['ascendants'], $class );
    foreach( $descendants as $class2 ){
      $whitelist[$class2] = true;
      $this->populateWithDescendants( $class2, $whitelist );
    }
  }
  
  private function pruneStructure(){
    if (0==count($this->baseClasses)){
      return;
    }
    $whitelist = array();
    foreach( $this->baseClasses as $class ){
      $whitelist[$class] = true;
      $this->populateWithDescendants( $class, $whitelist );
    }
    $this->structure['classes'] = $whitelist;
    foreach( array_keys( $this->structure['ascendants'] ) as $child ){
      $parent = $this->structure['ascendants'][$child];
      if (!(isset($whitelist[$child]) && isset($whitelist[$parent]))){
        unset( $this->structure['ascendants'][$child]);
      }
    }
    //var_export( $this->structure );
    //die("\n\nxx");
  }
  
  private function writeDotFile(){
    $this->stdOut( "digraph classes {
      node [ 
        fontsize=24, 
        shape=box, 
        style=filled,
        // try above as 'filled' or 'rounded' -- we can't have both
        color=black,
        fillcolor=skyblue1,
      ];
    \n");
    foreach( array_keys( $this->structure['classes'] ) as $class ){
      $label = $this->identifierToLabel( $class );
      $this->stdOut("$class [ label=\"$label\" ];\n"); // dbl quotes necessary on label
    }
    foreach( $this->structure['ascendants'] as $child => $parent ){
      $this->stdOut($child.'->'.$parent."\n");
    }
    
    if (count($this->structure['interfaces'])){
      $this->stdOut( "node [
        shape=box,
        style=rounded,
      ];\n");
    }
    
    foreach( array_keys( $this->structure['interfaces'] ) as $interface ){
      $label = $this->identifierToLabel( $interface );
      $this->stdOut( "$interface [ label=\"$label\" ];\n");
    }
    
    foreach( $this->structure['implements'] as $class => $interfaces ){
      foreach( $interfaces as $interface ){
        $this->stdOut( $class.'->'.$interface."\n" );
      }
    }
    
    
    
    $this->stdOut("}\n");
  }
  
  private function identifierToLabel( $identifier ){
    return preg_replace( '/([a-z])([A-Z])/', '$1\n$2', $identifier );    
  }
  
  private function getClassInfoInFile( $file ){
    $contents = file_get_contents( $file );
    preg_match_all( 
      $this->regex,
      $contents, 
      $matches, 
      PREG_SET_ORDER|PREG_OFFSET_CAPTURE // offset capture for future
    );
    $count = count( $matches );
    for( $i = 0; $i < $count; $i ++ ) {
      $class = $matches[$i][1][0]; // 1 is first capture, zero means the match not the offset
      if (isset($this->structure['defined_in_file'][$class])){
        $msg = "ERROR: sorry, class $class appears to be defined in both of the following files: \n  ".
        $this->structure['defined_in_file'][$class]."\n  ".$file."\n";
        $this->stdErr( $msg ); // we used to throw an exception here
      }
      $this->structure['classes'][$class] = 1;
      $this->structure['defined_in_file'][$class] = $file;
      // the extends portion of the capture will be ('',-1) when there was no extends but an implements 
      if (isset($matches[$i][2]) && (''!==$matches[$i][2][0])){ 
        $extends = $matches[$i][2][0];
        $this->structure['classes'][$extends] = 1;
        $this->structure['ascendants'][$class] = $extends;
      }
      if (isset($matches[$i][3])){
        $commaSeparatedList = $matches[$i][3][0];
        $implements = split(',',$commaSeparatedList);
        array_walk( $implements, create_function('&$x', '$x = trim($x);'));
        foreach( $implements as $interface ){
          $this->structure['interfaces'][$interface] = 1; // possibly redundant
          $this->structure['implements'][$class] = $implements;
        }
      }
    }
  }
}

class ArgumentException extends Exception { }

class SoftError extends Exception { }

class StdLogger{
  public function out( $msg ){
    fwrite( STDOUT, $msg );
  }
  public function err( $msg ){
    fwrite( STDERR, $msg );
  }
}

/**
* This manages everything for running ClassGraph from the command line.  Its only public method is processInput()
*/
class ClassGraphCli {

// --------- Public Methods ---------------------------------------------

  public function __construct(){ }
   
  public function processInput( $args ) {
    $this->graph = new ClassGrapher();
    error_reporting( E_ALL | E_NOTICE );
    $this->execName = 'php '.$args[0];
    array_shift( $args );
    try {
      $arg = $this->swallowArgs( $args, array(
        'make example parameters file',        
        'make dot file',
        'show search files',
        'show find commands',        
        'help',
      ));
    } catch ( ArgumentException $e ) {
      $this->stderr( $e->getMessage() .".\n"
        .$this->getMessage( 'usage message' )."\n"
      );
      $this->quit();
    }    
    $funcName = "do_".str_replace( ' ', '_', $arg );    
    try {
      $this->$funcName( $args );      
    } catch( ArgumentException $e ){
      $this->stderr( $e->getMessage()."\n" );
    }
  }
  
  // --------------- Protected Methods --------------------------------
  // ------- documentation -------
  
  private function getMessageTemplate( $name ){
    switch( $name ){
      case "no arguments": 
        $ret = array('Type \'','exec_name',' help\' for usage.'); 
      break;
      case "usage message": 
        $ret = array('Usage: ','exec_name'," <subcommand> [args]\n\n".
    // Type \'', 'exec_name', ' help <subcommand>\' for help on a specific subcommand. 
        "Available subcommands: \n".
        "  ".$this->subCommandOneLine('gen example')."\n".    
        "  ".$this->subCommandOneLine('show find commands')."\n".        
        "  ".$this->subCommandOneLine('show search files')."\n".
        "  ".$this->subCommandOneLine('make dot file')."\n"        
        );    
      break;
    }
    return $ret;
  } 
  
  private function subCommandOneLine( $name ){
    switch( $name ){
      case 'show find commands':
      case 'show search files':
      case 'make dot file':
        $ret = $name.' <parameters-file> [<directory>[...]]';
      break;
      case 'gen example':
        $ret = 'make example parameters file <filename>';
      break;
      default:
        die("bad case $name ".basename(__FILE__).__LINE__);
    }
    return $ret;
  }
  
  // ------------- business logic -------------
  
  private function do_show_search_files( $args ){
    $params = $this->commonParseAndValidateArgs( $args, 'show search files' );
    $this->graph->setParams( $params );    
    $this->stdout( join("\n",$this->graph->getSearchFiles() ) );
  }
  
  private function do_show_find_commands( $args ){
    $params = $this->commonParseAndValidateArgs( $args, 'show find commands' );
    $this->graph->setParams( $params );
    $this->stdout( "\n".join("\n\n",$this->graph->getFindCommands() )."\n\n" );
  }
  
  private function do_help( $args ){
    if (count($args)){
      $this->stdout( "ignoring argument(s): ".join(' ',$args)."\n");
    }
    $this->stdout( $this->getMessage( "usage message" ). "\n" );
    $this->quit();
  }
  
  private function do_make_example_parameters_file( &$args ){
    if (count($args)!==1){
      throw new ArgumentException( $this->getSubCommandUsage( 'gen example' ) );
    }
    $file = array_shift( $args );
    while(true) {
      if (!preg_match('/.php$/',$file )){
        $file .= ".php";
      }
      if (file_exists($file)) {
        $answer = $this->yesNoCancel("file \"$file\" exists.  Overwrite? ","no");
        switch( $answer ){
          case 'yes': break 2;
          case 'no':  $file = $this->prompt("choose a new filename: "); break;
          case 'cancel': $this->quit();
        }
      } else {
        break;
      }
    }
    if (!file_put_contents( $file,                                                    
     "<?php                                                                           \n".
     "return array(                                                                   \n".
     "  // one day this will probably be yaml                                         \n".
     "  'filename patterns to include' => array( '*.php' ),                           \n".
     "  'directory names to ignore'    => array( '.svn', 'cache', 'log', 'vendor' ),  \n".
     "\n".
     "  // if the below is the empty array, it means any classes.  Else limit the tree(s)\n".
     "  // to only those classes descending from these classes, and these classes.  \n".
     "  'base classes'                 => array(),                                  \n".
     "\n".
     "  // if the below list is empty, it expects them on the command line           \n".
     "  'search folders'               => array(),\n".
     ");                                                                              "
     )){
      throw new Exception("couldn't write to file: \"$file\""); 
    }
    $this->stdout( "wrote example parameters file to \"$file\".\nPlease open it and edit it if necessary.\n");
  }  

  private function do_make_dot_file( $args ){
    $params = $this->commonParseAndValidateArgs( $args, 'make dot file');
    $this->graph->setParams( $params );
    try {
      $this->graph->writeDotToStdOut();
    } catch( Exception $e ) {
      $this->stderr( "caught exception: (rewrite to show stack trace:)".$e->getMessage()."\n" );
    }
  }
  
  private function commonParseAndValidateArgs( &$args, $subCommand ){
    if (count($args) < 1){
      throw new ArgumentException( $this->getSubCommandUsage( $subCommand ));
    }
    $phpParamsFile = array_shift( $args );
    $params = require( $phpParamsFile );
    $c1 = count($params['search folders']);
    $c2 = count($args);
    if (($c1==0 && $c2==0) || ($c1>0 && $c2>0)){
      throw new ArgumentException( $this->getSubCommandUsage( $subCommand ) .
      "\nYou must define search folders either in your parameters file or on the command line, not both."
      );
    }
    if ($c2) { $params['search folders'] = $args; }
    return $params;
  }
      
  // --------------- Candidates for Abstraction ----------------------
  // these could be put into a base class for command line processors
  
  private function stdout( $msg ) {
    fwrite( STDOUT, $msg );
  }
  
  private function stderr( $msg ){
    fwrite( STDERR, $msg );
  }
  /* from the given allowed sequences of words, build an fsa.  ridiculous. */
  private function swallowArgs( &$args, $syntax ){
    $fsa = $this->getFsa( $syntax );
    if (count($args)==0){
      $this->stdout( $this->getMessage("no arguments") ."\n\n" );
      exit();
    }
    $nextStates =& $fsa[ 0 ];    
    $processedTokens = array();
    $atEnd = false;
    do {
      $token = array_shift( $args );
      if (in_array( $token, $nextStates )){
        $processedTokens []= $token;
        $nextStates =& $fsa[ $token ];
        if (count($nextStates) == 0){
          $atEnd = true;
        }
      } else {
        $msg = $this->getParseError( "Unexpected word \"$token\"", $processedTokens, $nextStates );
        throw new ArgumentException( $msg );
      }
    } while ( count( $args ) && ! $atEnd );
    if (count($nextStates) != 0 ){
      $msg = $this->getParseError( "Unexpected end of input", $processedTokens, $nextStates );
      throw new ArgumentException( $msg );
    }
    return join( ' ', $processedTokens );
  }
  
  private function getParseError( $unexpectedMsg, $processedTokens, $nextStates ){
    return $unexpectedMsg.
    (
      count( $processedTokens ) 
        ? ( " after \"".join(' ',$processedTokens )."\"" ) 
        : ( " at beginning of input" )
    ).".  Expecting ".
    (
      ( count( $nextStates ) == 1 )
      ? ( "\"".$nextStates[0]."\"")
      : ( "one of (".join(", ", array_map(create_function( '$x', 'return \'"\'.$x.\'"\';'), $nextStates)).')')
    );  
  }
  
  private function getFsa( $syntax ){
    $fsa = array( 0 => array() );
    foreach( $syntax as $non_terminal ){
      $terminals = split( " ", $non_terminal );
      $validNextStates =& $fsa[0];      
      foreach( $terminals as $terminal ){
        if (!in_array($terminal, $validNextStates)){
          $validNextStates []= $terminal;
        }
        if (!isset($fsa[$terminal])){
          $fsa[$terminal] = array();
        }
        $validNextStates =& $fsa[$terminal];
      }
      if (!isset($nodes[$terminal])){
        $nodes[$terminal] = array();
      }
    }
    return $fsa;
  }
  
  
  /** 
  * @param array ary is the message array, alternating body copy and placeholders.
  * @param array args is key value pairs, the keys being placeholders and the values being strings to substitue
  * in the message string.
  */
  private function getMessage( $name, $args = null ){
    if (null===$args) { $args = array(); }
    $ary = $this->getMessageTemplate( $name );
    $args += array( 
      'exec_name' => $this->execName
    );
    $size = count($ary);
    // indexes that are odd are placeholders 
    $outString = '';
    for ($i=0; $i<$size; $i++){
      $outString .= ( ( 0 == $i % 2 ) ? $ary[$i] : $args[$ary[$i]] );
    }
    return $outString;
  }
  
  private function getSubCommandUsage( $name ){
    return "Usage:\n  ".$this->subCommandOneLine( $name );
  }

  /*
    ask a user a question on the command-line and require a yes or no answer (or cancel.) 
    return 'yes' | 'no' | 'cancel'   loops until one of these is entered 
    if default value is provided it will be used if the user just presses enter w/o entering anything.
    if default value is not provided it will loop.
  */
  function yesNoCancel( $prompt, $defaultValue = null){
    $done = false;
    if (null!==$defaultValue) {
      $defaultMessage  =" (default: $defaultValue)";
    }
    while (!$done){
      $this->stdout( $prompt );
      if (strlen($prompt)&&"\n"!==$prompt[strlen($prompt)-1]){ $this->stdout("\n"); }
      $this->stdout( "Please enter [y]es, [n]o, or [c]ancel".$defaultMessage.": ");
      $input = strtolower( trim( fgets( STDIN ) ) );
      if (''===$input && null!== $defaultValue) { 
        $input = $defaultValue;
      }
      $done = true;
      switch( $input ){
        case 'y': case 'yes': $return = 'yes'; break;
        case 'n': case 'no':  $return = 'no'; break;
        case 'c': case 'cancel': $return = 'cancel'; break;
        default: 
        $done = false;
      }
    }
    $this->stdout( "\n" ); // 2 newlines after user input for readability (the first was when they hit enter).
    return $return;
  }
  
  function prompt( $prompt ){
    $this->stdout( $prompt );
    $answer = trim( fgets( STDIN ) );
    return $answer;
  }
  
  function quit(){
    exit();
  }
  
}

/* we need to determine if this file is being used as in included library 
* or as a standalone CLI file.  under strange circumstances, if you do php -a
* and include this file in the interactive prompt, the first argument of $argv is ['-']
*/
if (isset($argv) && count($argv) && basename( __FILE__ ) == $argv[0] ){
  $cli = new ClassGraphCli();
  $cli->processInput( $argv );
}