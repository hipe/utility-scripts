<?php
/* 
this is a standalone file to be run from the commandline 
that imports a tab- or comma- delimited file into 
a database, creating a table if necessary.  

This might be useful if you want to run database-like queries against a tab-file 
(or spreadsheet).

It is basically a wrapper around the mysql LOAD DATA INFILE command with 
smarts for creating the table and erasing the data

The table it creates will match the "structure" of the csv file:

The column names of the database table will be lowercase-underscored
versions of the names of the column headers in the csv file

The datatype for each column of the table is inferred from the 
data in the tab-delimited file using the following strategy:

If all values in a column are an integer or the empty string, it will make the 
database column of type integer.
Else if all values match the regex for float or are the empty string, 
it will make the column of type float.
Else it will make the column of type text.

(This is sort of a sqlite-like simplification of datatypes)

Note: 

  this uses "load data infile local" for now, that is the tab file must be 
  on the same server as the database *client* (not server).  this could be changed.
*/
error_reporting( E_NOTICE | E_ALL );


// ** clasess ** 

class StdLogger {
  function out( $msg ){
    CliCommon::stdout( $msg );
  }
  function err( $msg ){
    CliCommon::stderr( $msg );
  }
}

class QueryException extends Exception {}

/* 
the bulk of this is logic to build the structure of the table,
and a wrapper around "load data infile"
*/
class TableFromCsvBuilderPopulator{
  
  protected $createPrimaryKey = false;  
  
  public function __construct( $args ){
    $this->logger       = $args['logger'];
    $this->separator    = $args['separator'];
    $this->csv_filename = $args['csv_filename'];
    $this->username = $args['connection_parameters']['username'];
    $this->password = $args['connection_parameters']['password'];
    $this->database = $args['connection_parameters']['database'];
    $this->table_name   = $args['table_name'];
    $this->fp = null; 
    $this->out_sql_filepath = "temp.".$this->table_name.".sql";
    $this->primaryKeyName = '_id'; // something not in the tab file
  }
  
  public function setDoPrimaryKey( $x ){
    $this->createPrimaryKey = true;
  }
  
  public function get_table_name(){
    return $this->table_name;
  }
  
  public function table_exists(){
    $q = "show tables where Tables_in_".$this->database." = '$this->table_name'";
    $rs = mysql_query( $q );
    return (bool) mysql_num_rows( $rs ); 
  }
  
  public function get_numrows_in_table(){
    $q = "select count(*) from `$this->table_name`";
    $rs = mysql_query( $q );
    $row = mysql_fetch_row( $rs );
    return $row[0];
  }
  
  public function create_table() {
    $this->infer_types_from_column_values();
    $sql = $this->get_sql_for_table_from_types();    
    $this->run_sql( $sql );
    $this->logger->out( "created table $this->table_name.\n" );
  }
  
  public function drop_table(){
    $q = "drop table `".$this->table_name."`";
    $result = $this->run_sql( $q );
    $this->logger->out( "dropped table $this->table_name.\n" );
  }
  
  public function delete_all_from_table() {
    $q = "delete from `".$this->table_name."`";
    $this->run_sql( $q );
    $num = mysql_affected_rows();
    $this->logger->out( "deleted $num rows from $this->table_name.\n" );
    if ($this->createPrimaryKey) {
      $this->run_sql( "alter table `$this->table_name` drop column 
        $this->primaryKeyName
      ");
      $this->logger->out( "dropped $this->primaryKeyName column from $this->table_name.\n" );      
    }
  }
    
  public function populate_table() {
    $this->run_sql( $this->get_load_data_query() );
    $this->pkCheck();
  }
  
  public function try_populate_table_workaround() {
    $fn = "temp.load_data_sql_statement.sql";
    if (false === file_put_contents($fn, $this->get_load_data_query())){
      $this->fatal( "couldn't open tempfile: $fn" );
    }
    $passwordArg = (''===$this->password) ? '' : (" -p=".$this->password);
    $command = "mysql ".$this->database." --local_infile=1 -u ".$this->username.$passwordArg." < $fn ;$this->password";
    $results = shell_exec( $command );
    if (NULL!==$results){ $this->fatal( "expected NULL had: '$results'"); }
    // we might want to keep the below file around for debugging purposes
    if (!unlink($fn)){ $this->fatal("couldn't erase file: $fn"); }
    $this->pkCheck();
  }
  
  private function pkCheck(){
    if (!$this->createPrimaryKey) return;
    $this->run_sql( "alter table `$this->table_name` 
      add column`$this->primaryKeyName` int(11) not null auto_increment primary key first");
    $this->logger->out( "added primary key column to table\n" );    
  }
      
  // ---------------- Protected Methods ----------------------
  
  protected function get_load_data_query(){
    switch( $this->separator ){
      case "\t": $terminator = '\\t'; break;
      case ',' : $terminator = ','; break;
      default:  $this->fatal( "we need to code for this terminator: \"$this->separator\"" );
    }    
    return "
    load data low_priority local infile '$this->csv_filename'
    into table `$this->table_name`
    fields terminated by '$terminator' enclosed by '' escaped by '\\\\'
    lines terminated by '\\n'
    ignore 1 lines
    ";
  }
  
  
  protected function run_sql( $q ){
    $ret = mysql_query( $q );
    if (false===$ret){
      throw new QueryException( 
        "something wrong with this query: ".var_export( $q,1 )."\n\n".
        "mysql returned the error: ".mysql_error()."\n"
      );
    }
    return $ret;
  }
  
  protected function open_file_if_necessary_and_reset_position(){
    $this->eof = false;
    if ( (is_resource( $this->fp ))) {
      fseek( $this->fp, 0 );
    } else {
      if ( ! $this->fp = fopen( $this->csv_filename, 'r' ) ) { 
        $this->fatal("couldn't open $this->csv_filename" ); 
      }
    } 
    
    if (!is_resource( $this->fp )) {
      $this->fatal("what in the whonanny is going on here?" );
    }
    $this->line_num = 0;
  }

  protected function get_next_row_of_cels(){
    $line = fgets( $this->fp );
    if (false===$line){
      $this->eof = true;
      $return = false;
    } else{
      $this->line_num ++;
      if (!feof( $this->fp )) {
        $line = trim( $line, "\n\r" );
      }
      $return = split( $this->separator, $line );
    }
    return $return;
  }

  protected function assert_last_line(){
    $this->get_next_row_of_cels();
    if (!feof($this->fp)){
      $this->fatal("expecting line number $this->line_num to be last line of file (because it was an empty line?)");
    }
  }
  
  protected function infer_types_from_column_values(){
    $this->open_file_if_necessary_and_reset_position();
    if (feof( $this->fp )) { fatal( "expecting column header row at beginning of file."); }
    $colNames = $this->get_next_row_of_cels();
    $colNameToIndex = array_flip( $colNames );
    $this->columnTypes = array_fill_keys( $colNames, "null_string" );
    $this->colsToResolve = array_combine( $colNames, $colNames );
    //$types = array( "null_string", "integer", "float", "text" );
    while( true ){
      $values = $this->get_next_row_of_cels();
      if ($this->eof) {
        break;
      }
      foreach( $this->colsToResolve as $col ){
        $colIndex = $colNameToIndex[$col];
        if (!isset($values[$colIndex] )) {
          echo "linenum: ".$this->line_num."\n";
          echo "colIndex: $colIndex\n";
          echo "cols to resolve: ";
          var_export( $this->colsToResolve );
          echo "col name to index name: ";
          var_export( $colNameToIndex );
          echo "values: ";
          var_export( $values );
            $this->fatal ('what gives');
        }
        
        $value = $values[$colIndex];
        $fallthrough = false;
        switch( $this->columnTypes[$col] ){
          case 'null_string':
            if ('' === $value) { break; }
            $fallthrough = true;
          case 'integer':
            if (''===$value || preg_match('/^-?\\d+$/', $value)){
              if ($fallthrough) {  $this->columnTypes[$col] = 'integer'; }
              break;
            } else {
              $fallthrough = true;
            }
          case 'float':
            if (''===$value || preg_match('/^-?\\d+(?:\.\d+)?$/', $value)) {
              if ($fallthrough) { $this->columnTypes[$col] = 'float'; }
              break;
            } else {
              $fallthrough = true;
            }
          case 'text': 
            if (!$fallthrough) { $this->fatal("something is wrong with my logic here"); }
            $this->columnTypes[$col] = 'text';
            unset( $this->colsToResolve[$col] );
            if (count($this->colsToResolve) == 0 ) { break 2; } // don't bother reading the rest of the lines
            break;
          default:
            $this->fatal( "bad case" );
        }
      } // each column to resolve
    } // each line of file
  }
  
  protected function get_sql_for_table_from_types() {
    $q = "create table $this->table_name (\n  ";
    $dbCols = array();
    foreach( $this->columnTypes as $colName => $colType ){
      $dbColName = $this->convert_column_name_from_csv_to_db( $colName );
      if ('null_string' === $colType) { $colType = 'text'; } // what do to on a field with all empty strings?
      $dbCols []= "$dbColName $colType";
    }
    $q .= join( ",   \n", $dbCols );
    $q .= "\n)";
    return $q;
  }
  
  protected function convert_column_name_from_csv_to_db( $name ){
    return strtolower( preg_replace(
      array( '/ +/', '/[^A-Za-z0-9_]+/' ),
      array( '_', '' ),
      $name
    ));
  }
}

/**
* candidates for abstraction
*/
class CliCommon {

  /*
    ask a user a question on the command-line and require a yes or no answer (or cancel.) 
    return 'yes' | 'no' | 'cancel'   loops until one of these is entered 
    if default value is provided it will be used if the user just presses enter w/o entering anything.
    if default value is not provided it will loop.
  */
  public static function yes_no_cancel( $prompt, $defaultValue = null){
    $done = false;
    if (null!==$defaultValue) {
      $defaultMessage  =" (default: $defaultValue)";
    }
    while (!$done){
      echo $prompt;
      if (strlen($prompt)&&"\n"!==$prompt[strlen($prompt)-1]){ echo "\n"; }
      echo "Please enter [y]es, [n]o, or [c]ancel".$defaultMessage.": ";
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
    echo "\n"; // 2 newlines after user input for readability (the first was when they hit enter).
    return $return;
  }  
  
  public static function stdout( $str ) {
    fwrite( STDOUT, $str );
  }

  public static function stderr( $str ) {
    fwrite( STDERR, $str );
  }
}

/**
* in a standalone manner, handle all the issues with command line proccessing
*/
class Tab2TableCli {
  
  public function processArgs( $argv ){
    if (count($argv) < 2 || (!in_array($argv[1], array('import','example')))) {
      $this->fatal( $this->get_usage_message() );  
    }
    array_shift( $argv );
    $verb = array_shift( $argv );
    $methName = 'do_'.str_replace( ' ','_', strtolower( $verb ) );
    $this->$methName( $argv );
  }
    
  // **** Protected Methods ****

  function get_usage_message(){
    return "Usage:\n".
    "    php ".$GLOBALS['argv'][0].' import <parameters file> <input csv>'."\n".
    "\n".
    "to output an example parameters file:\n".
    "    php ". $GLOBALS['argv'][0].' example <parameters file name>'."\n "
    ;
  }  
  
  protected function do_example( $args ){
    if (1 !== count( $args ) ) {
      $this->fatal( 
        "expecting exactly one argument for filename.\n".$this->get_usage_message() 
      );
    }
    $fn = $args[0];
    if (file_exists( $fn )){
      $this->fatal( "example parameters file must not already exist \"$fn\"");
    }
    $s = <<<TO_HERE
<?php
    return array(
      'connection' => array(
        'username' => 'root',
        'password' => '',
        'server'   => 'localhost',
        'database' => 'sf3_dev',
      ),
      'table_name' => '_temp',
    );
TO_HERE;
    file_put_contents( $fn, $s );
    $this->stdout( "wrote example parameters file to \"$fn\".\n" );
  }
  
  protected function do_import( $argv ){
    if (count($argv) != 2){
      $this->fatal( "needed exactly two arguments.\n". $this->get_usage_message() );
    }
    $args['data_file'] = $argv[0];
    $args['input_csv'] = $argv[1];
    foreach( array( 'data_file','input_csv') as $filename_name ) {
      if (!file_exists( $filename = $args[$filename_name])) {
        $this->fatal( "there is no $filename_name with the path \"$filename\"" );
      }
    }   
    $data = require_once( $args['data_file'] );
    $tbl_name = $data['table_name'];
    $c = $data['connection'];
    if (! $cn = mysql_connect( $c['server'], $c['username'], $c['password'] ) ){
      $this->fatal( "can't connect with ".var_export( $c, 1));
    }
    if (!mysql_select_db( $c['database'] ) ) { 
      $this->fatal( "can't select database: ".$c['database'] ); 
    }
    if (!preg_match('/^_temp/', $tbl_name)) {
      $this->fatal( "table name must start with \"_temp\" -- invalid table name \"$tbl_name\"" );
    } 
    $builder = new TableFromCsvBuilderPopulator(array(
      'logger'       => new StdLogger(),
      'separator'    => "\t",
      'csv_filename' => $args['input_csv'],
      'connection_parameters' => $c,
      'table_name'   => $tbl_name
    ));
    $builder->setDoPrimaryKey( true );
    $doDropTable = false;
    $doCreateTable = false;    
    if ($builder->table_exists()) {
      $choice = CliCommon::yes_no_cancel( "table \"$tbl_name\" exists. Should we recreate its structure from the *.csv file? (say 'yes' if you think the struture has changed.)\n", "no" );
      if ('cancel'===$choice) { 
        $this->quit();
      } elseif ( 'yes' === $choice ) {
        $doDropTable = true;
        $doCreateTable = true;
      } 
    } else {
      $doCreateTable = true;
    }
    if ($doDropTable) { $builder->drop_table(); }
    if ($doCreateTable) { $builder->create_table(); }
    if ($num = $builder->get_numrows_in_table()){
      $choice = CliCommon::yes_no_cancel( "table ".$builder->get_table_name()." has $num rows of data in it.  Is it ok to delete this data?", 'yes' );
      if ($choice != 'yes') { $this->quit(); }
      $builder->delete_all_from_table();  
    }
    try { 
      $builder->populate_table();
    } catch ( QueryException $e ) {
      $str = "mysql returned the error: The used command is not allowed with this MySQL version";
      if (false !== strstr( $e->getMessage(), $str)){
        $this->stdout( "$str\n" );
        $this->stdout( "it is expected to be because the mysql server wasn't started with --local-infile enabled. \n");
        $choice = CliCommon::yes_no_cancel( "Should we try the workaround, to exec LOAD DATA INFILE from the command line? ",'yes' );
        if ($choice !== 'yes') { $this->quit(); }
        $builder->try_populate_table_workaround();   
      } else {
        throw $e;
      }
    }
    $this->stdout("done.\n"); 
  }

  function quit(){
    $this->stdout( "quitting.\n");
    exit();
  }

  // php != mixins
  function stdout( $str ){ CliCommon::stdout( $str ); }
  function stderr( $str ){ CliCommon::stderr( $str ); }

  function fatal( $msg ){
    $this->stdout( $msg."\n" );
    die();
  }

}

$cli = new Tab2TableCli();
$cli->processArgs( $argv );