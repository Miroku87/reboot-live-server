<?php
//https://www.leaseweb.com/labs/2015/10/creating-a-simple-rest-api-in-php/
$path = $_SERVER['DOCUMENT_ROOT']."/reboot-live-api/";
include_once( $path."classes/UsersManager.class.php" );

class Main
{
	protected $usersmanager;
	
	public function __construct()
	{
		ini_set( 'html_errors', false );

		date_default_timezone_set( 'Europe/Rome' );

		header( 'Content-Type: application/json' );
		header( 'Access-Control-Allow-Origin: *' );

		$this->usersmanager = new UsersManager();
		$this->runAPI();
	}
	
	public function __destruct()
	{
	}
	
	private function runAPI()
	{
		// get the HTTP method, path and body of the request
		$method  = $_SERVER['REQUEST_METHOD'];
		$request = explode( '/', trim( $_SERVER['PATH_INFO'], '/' ) );
		$input   = json_decode( file_get_contents( 'php://input' ), true );
		
		if( $method == "GET" )
		{
			echo call_user_func_array( array( $this->$request[0], $request[1] ), $_GET );
		}
		else if ( $method == "POST" )
		{
			
		}
		else if ( $method == "PUT" )
		{
			
		}
		else if ( $method == "DELETE" )
		{
			
		}
	}
}

$main = new Main();
?>