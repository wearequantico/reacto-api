<?php

namespace Wearequantico\ReactoApi;

use GuzzleHttp\Client; 
use GuzzleHttp\RequestOptions; 
use Wrapper;
 
if (isset($_GET['view_error'])) {
    ReactoApiService::viewError(); // leggerà $_GET['view_error']
    exit;
}

/**
 * ReactoApiService 
 */
class ReactoApiService   {
	/**
	 * Default class map for wsdl=>php
	 * @access private
	 * @var array
	 */
	private static $classmap = array(
	);
		private static $client;
		private static $client_url; 

	/**
	 * Constructor using wsdl location and options array
	 * @param string $wsdl WSDL location for this service
	 * @param array $options Options for the SoapClient
	 */
	public function __construct($wsdl=WSDL_LOC, $options=array()) { 

		if(!isset($options['timeout'])) {
			$options['timeout'] = 30.0;
		}
		
		if(!isset($options['connect_timeout'])) {
			$options['connect_timeout'] = 5.0;
		}
		$this->client_url = $wsdl;
		$this->client = new Client($options);

	}
 
	public function __soapCall($function_name, $arguments, $options = null) 
	{   	

	//send the request
	try {  
	$response =	$this->client->post($this->client_url,[  
						'headers' => [ 
							'Content-type' => 'application/json' ,
        					'Accept' => 'application/json',
							'X-HTTP-Method-Override' => $function_name
						],
						RequestOptions::JSON => (count($arguments) > 0 ? $arguments : null)
					]);
					
 		//var_dump($function_name,$arguments);
		
		$result = preg_replace("!\r?\n!", "",  $response->getBody()->getContents()); 
	
	} catch (\Exception $e) {
		$errorId = $this->_saveError($function_name, $arguments, $e);
		$this->_displayErrorPage($errorId);
		die();
	} 
	return $result; 
	}

	/**
	 * Salva l'errore in sessione e restituisce un ID univoco
	 * @param string $function_name Nome della funzione chiamata
	 * @param array $arguments Argomenti passati alla funzione
	 * @param \Exception $exception Eccezione catturata
	 * @return string ID univoco dell'errore
	 */
	private function _saveError($function_name, $arguments, $exception) {
		if (session_status() === PHP_SESSION_NONE) {
			@session_start();
		}
		
		$errorId = $this->_generateErrorId();
		$timestamp = date('Y-m-d H:i:s');
		$errorData = array(
			'id' => $errorId,
			'timestamp' => $timestamp,
			'function_name' => $function_name,
			'arguments' => $arguments,
			'message' => $exception->getMessage(),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
			'trace' => $exception->getTraceAsString(),
			'client_url' => $this->client_url
		);
		
		// Log nell'error log di Apache
		$argumentsJson = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$logMessage = sprintf(
			"[REACTO_API_ERROR] ID: %s | %s | Function: %s | Message: %s | File: %s:%d | URL: %s | Arguments: %s | Trace: %s",
			$errorId,
			$timestamp,
			$function_name,
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine(),
			$this->client_url,
			$argumentsJson,
			str_replace(array("\r\n", "\n", "\r"), " ", $exception->getTraceAsString())
		);
		error_log($logMessage);
		
		if (!isset($_SESSION['reacto_errors'])) {
			$_SESSION['reacto_errors'] = array();
		}
		
		$_SESSION['reacto_errors'][$errorId] = $errorData;
		
		// Mantieni solo gli ultimi 50 errori per evitare che la sessione diventi troppo grande
		if (count($_SESSION['reacto_errors']) > 50) {
			$keys = array_keys($_SESSION['reacto_errors']);
			$oldestKey = array_shift($keys);
			unset($_SESSION['reacto_errors'][$oldestKey]);
		}
		
		return $errorId;
	}

	/**
	 * Genera un ID univoco per l'errore
	 * @return string ID univoco
	 */
	private function _generateErrorId() {
		return bin2hex(random_bytes(16));
	}

	/**
	 * Mostra la pagina HTML di errore all'utente
	 * @param string $errorId ID dell'errore
	 */
	private function _displayErrorPage($errorId) {
		$templatePath = dirname(__DIR__) . '/templates/error.html';
		
		if (file_exists($templatePath)) {
			$html = file_get_contents($templatePath);
			$html = str_replace('{ERROR_ID}', $errorId, $html);
			header('Content-Type: text/html; charset=UTF-8');
			echo $html;
		} else {
			// Fallback se il template non esiste
			echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Errore</title></head><body>';
			echo '<h1>Servizio momentaneamente non disponibile</h1>';
			echo '<p>Ci scusiamo per l\'inconveniente. Si prega di riprovare più tardi.</p>';
			echo '<p>Codice errore: <strong>' . htmlspecialchars($errorId) . '</strong></p>';
			echo '</body></html>';
		}
	}

	/**
	 * Metodo statico per visualizzare i dettagli completi di un errore
	 * Utilizzare: ReactoApiService::viewError('error_id')
	 * Oppure tramite GET: ?view_error=error_id
	 * @param string|null $errorId ID dell'errore (se null, cerca in $_GET['view_error'])
	 * @return void
	 */
	public static function viewError($errorId = null) {
		if ($errorId === null && isset($_GET['view_error'])) {
			$errorId = $_GET['view_error'];
		}
		
		if (empty($errorId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'ID errore non fornito'));
			return;
		}
		
		// Validazione formato ID (solo caratteri esadecimali, 32 caratteri)
		if (!preg_match('/^[a-f0-9]{32}$/i', $errorId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'ID errore non valido'));
			return;
		}
		
		if (session_status() === PHP_SESSION_NONE) {
			@session_start();
		}
		
		if (!isset($_SESSION['reacto_errors']) || !isset($_SESSION['reacto_errors'][$errorId])) {
			http_response_code(404);
			echo json_encode(array('error' => 'Errore non trovato'));
			return;
		}
		
		$errorData = $_SESSION['reacto_errors'][$errorId];
		
		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	}  
	/**
	 * Checks if an argument list matches against a valid argument type list
	 * @param array $arguments The argument list to check
	 * @param array $validParameters A list of valid argument types 
	 */
	public function _checkArguments($arguments, $validParameters) {

    if (empty($validParameters)) {
        return array();
    }
     
    // Tipizza gli argomenti
    $validatedArgs = array();
    foreach ($arguments as $index => $value) {
        if ($index >= count($validParameters)) {
            break; // Ignora argomenti extra
        }
        
		//Rimosso, qualsiasi parametro abbia definito nella funzione dev'essere tipizzato
		/*
        if ($value === null || $value === "~~NULL~~") {
            $validatedArgs[] = $value;
            continue;
        } */
        
        $expectedType = $validParameters[$index];
        
        switch ($expectedType) {
            case 'integer':
                $validatedArgs[] = (int)$value;
                break;
            case 'string':
                $validatedArgs[] = (string)$value;
                break;
            case 'double':
                $validatedArgs[] = (double)$value;
                break;
            case 'dateTime': 
				//deve essere date("c", strtotime($DATA))
				//controllo se è un timestamp
				$is_timestamp = (is_numeric($value)) && ($value <= PHP_INT_MAX) && ($value >= ~PHP_INT_MAX);  
                //ulteriore cast, non necessario
				// $validatedArgs[] = $is_timestamp ?  date("c", (int)$value) : date("c", strtotime((string)$value)); 
				$validatedArgs[] = $is_timestamp ?  date("c", (int)$value) : (string)$value;
                break;
            case 'base64Binary':
            case 'ns1anyTypeArray':
            case 'ns2:anyTypeArray':
            default:
                $validatedArgs[] = $value;
                break;
        }
    }
    
    return $validatedArgs;
}

	/**
	 * Service Call: init
	 * Parameter options:

	 * @param mixed,... See function description for parameter options
	 * @return 
	 * @throws Exception invalid function signature message
	 */
	public function init($mixed = null) {
		$validParameters = array("");

		$args = $this->_checkArguments(func_get_args(), $validParameters);
		return $this->SoapXmlDecode($this->__soapCall("init", $args));
	}


	//CONVERSIONE DA JSON AD ARRAY , VIENE CHIAMATA DA TUTTE LE CLASSI WBSERVICE
//Scala l'oggetto ricevuto e lo converte in un oggetto standard 
// i risultati  si accedono con $obj[num][NOMECAMPO] (o ciclo foreach)
// se la funzione non restituisce risultati (esempio un setter) restituisce null
public function SoapXmlDecode($json)
{
	//se la funzione non restituisce nulla (es. un setter) restituisce null
	// (i risultati senza record sono comunque un recordest)
	if ($json === 0 || $json == "0" || $json == "false" || $json == "FALSE") {
		return null;
	}

	//se la funzione ritorna un booleano true (es un controllo) restituisce true
	if ($json === 1 || $json == "1" || $json == "true" || $json === -11 || $json == "-1") {
		return true;
	}
	$obj = json_decode($json, TRUE);
	$newarray = $obj["TableData"];
	//i risultati singoli sono restituiti come array, quelli multipli come array di array
	// questo controllo riporta gli array singoli alla multidimensione
	if (isset($newarray["Row"]) && count($newarray["Row"]) == count($newarray["Row"], COUNT_RECURSIVE)) {
		$newarray["Row"] = array($newarray["Row"]);
	}
	//risale di un livello (elimina il contenitore "Row"
	$newarray  = $newarray["Row"];

	if (!$newarray[0])
		$newarray = null;

	$obj = new Wrapper($newarray);

	return $obj;
}


	/**
	 * Service Call: ID_DOExecute
	 * Parameter options:
	 * (string) DOXML, (string) MethodName, (string) ClassName, (ns1anyTypeArray) Params, (integer) RetDoc
	 * @param mixed,... See function description for parameter options
	 * @return ns2:anyTypeArray
	 * @throws Exception invalid function signature message
	 */
	public function ID_DOExecute($mixed = null) {
		$validParameters = array("string", "string", "string", "ns1anyTypeArray", "integer");

		$parameterNames = array("DOXML", "MethodName", "ClassName", "Params", "RetDoc");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ID_DOExecute", $namedArgs));
	}


	/**
	 * Service Call: GetCompoLavaggi
	 * Parameter options:
	 * (integer) pIdprod, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCompoLavaggi($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("Idprod", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCompoLavaggi", $namedArgs));
	}


	/**
	 * Service Call: GetProdotti
	 * Parameter options:
	 * (integer) pidStore, (string) plingua, (integer) pidUtente, (integer) pidSoggetto
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProdotti($mixed = null) {
		$validParameters = array("integer", "string", "integer", "integer");

		$parameterNames = array("idStore", "lingua", "idUtente", "idSoggetto");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetProdotti", $namedArgs));
	}


	/**
	 * Service Call: GetProdottiByListinoPers
	 * Parameter options:
	 * (integer) pidStore, (string) plingua, (integer) pidSoggetto
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProdottiByListinoPers($mixed = null) {
		$validParameters = array("integer", "string", "integer");

		$parameterNames = array("idStore", "lingua", "idSoggetto");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetProdottiByListinoPers", $namedArgs));
	}


	/**
	 * Service Call: AbilitaDisabilitaProdByListinoPers
	 * Parameter options:
	 * (integer) pidStore, (integer) pidSoggetto, (string) pprodotti, (integer) pabilita
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function AbilitaDisabilitaProdByListinoPers($mixed = null) {
		$validParameters = array("integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "idSoggetto", "prodotti", "abilita");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("AbilitaDisabilitaProdByListinoPers", $namedArgs));
	}


	/**
	 * Service Call: GetQtaStock
	 * Parameter options:
	 * (integer) pidStore, (integer) pidFasciaStock, (integer) pqtaStock, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetQtaStock($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idFasciaStock", "qtaStock", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetQtaStock", $namedArgs));
	}


	/**
	 * Service Call: GetVariantiProdByLivello
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (integer) pvar1, (integer) pvar2, (integer) pvar3, (integer) plivello, (string) pLingua, (integer) pUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetVariantiProdByLivello($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "ID", "var1", "var2", "var3", "livello", "Lingua", "Utente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetVariantiProdByLivello", $namedArgs));
	}


	/**
	 * Service Call: GetVariantiProdMatrice
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (integer) pvar1, (integer) pvar2, (integer) pvar3, (integer) plivelloFoto, (string) pLingua, (integer) pUtente, (integer) pprezzi
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetVariantiProdMatrice($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "string", "integer", "integer");

		$parameterNames = array("idStore", "ID", "var1", "var2", "var3", "livelloFoto", "Lingua", "Utente", "prezzi");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetVariantiProdMatrice", $namedArgs));
	}


	/**
	 * Service Call: GetVarianti
	 * Parameter options:
	 * (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetVarianti($mixed = null) {
		$validParameters = array("string");

		$parameterNames = array("lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetVarianti", $namedArgs));
	}


	/**
	 * Service Call: GetVariantiProdByLivelloCompleta
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (integer) pvar1, (integer) pvar2, (integer) pvar3, (integer) plivello, (string) pLingua, (integer) pUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetVariantiProdByLivelloCompleta($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "ID", "var1", "var2", "var3", "livello", "Lingua", "Utente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetVariantiProdByLivelloCompleta", $namedArgs));
	}


	/**
	 * Service Call: GetGruppiVarianti
	 * Parameter options:
	 * (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGruppiVarianti($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGruppiVarianti", $namedArgs));
	}


	/**
	 * Service Call: GetGruppiVariantiBySlug
	 * Parameter options:
	 * (string) pslug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGruppiVariantiBySlug($mixed = null) {
		$validParameters = array("string", "string");

		$parameterNames = array("slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGruppiVariantiBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetPrezzo
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (integer) pidListino, (integer) pidProd, (integer) pidValVar1, (integer) pidValVar2, (integer) pidValVar3, (integer) pidCustom1, (string) pcustom1, (integer) pidCustom2, (string) pcustom2, (integer) pidCustom3, (string) pcustom3, (integer) pidCustom4, (string) pcustom4, (integer) pidCustom5, (string) pcustom5, (double) pquantita
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPrezzo($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "integer", "string", "integer", "string", "integer", "string", "integer", "string", "double");

		$parameterNames = array("idStore", "idUtente", "idListino", "idProd", "idValVar1", "idValVar2", "idValVar3", "idCustom1", "custom1", "idCustom2", "custom2", "idCustom3", "custom3", "idCustom4", "custom4", "idCustom5", "custom5", "quantita");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPrezzo", $namedArgs));
	}


	/**
	 * Service Call: dammidata
	 * Parameter options:

	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function dammidata($mixed = null) {
		$validParameters = array("");

		$args = $this->_checkArguments(func_get_args(), $validParameters);
		return $this->SoapXmlDecode($this->__soapCall("dammidata", $args));
	}


	/**
	 * Service Call: GetTabellaPrezzi
	 * Parameter options:
	 * (integer) pIDprod, (integer) pIDutente, (integer) pfull
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTabellaPrezzi($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("IDprod", "IDutente", "full");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTabellaPrezzi", $namedArgs));
	}


	/**
	 * Service Call: GetWishlist
	 * Parameter options:
	 * (integer) pID
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetWishlist($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("ID");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetWishlist", $namedArgs));
	}


	/**
	 * Service Call: GetWishlistByUtente
	 * Parameter options:
	 * (integer) pIDutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetWishlistByUtente($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("IDutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetWishlistByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetWishlistByHash
	 * Parameter options:
	 * (string) phash
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetWishlistByHash($mixed = null) {
		$validParameters = array("string");

		$parameterNames = array("hash");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetWishlistByHash", $namedArgs));
	}


	/**
	 * Service Call: SetWishlist
	 * Parameter options:
	 * (integer) pIDHash, (integer) pIDutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetWishlist($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("IDHash", "IDutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetWishlist", $namedArgs));
	}


	/**
	 * Service Call: UpdateWishlist
	 * Parameter options:
	 * (string) phash, (integer) pIDutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateWishlist($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("hash", "IDutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateWishlist", $namedArgs));
	}


	/**
	 * Service Call: AddProdWishlist
	 * Parameter options:
	 * (integer) pIDhash, (integer) pIDutente, (integer) pIDprod, (integer) pqta
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function AddProdWishlist($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer");

		$parameterNames = array("IDhash", "IDutente", "IDprod", "qta");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("AddProdWishlist", $namedArgs));
	}


	/**
	 * Service Call: GetProdByWishlist
	 * Parameter options:
	 * (integer) pidStore, (integer) pidHash, (integer) pIDutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProdByWishlist($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idStore", "idHash", "IDutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		 
		
		return $this->SoapXmlDecode($this->__soapCall("GetProdByWishlist", $namedArgs));
	}


	/**
	 * Service Call: DelProdWishlist
	 * Parameter options:
	 * (integer) pIDhash, (integer) pIDutente, (integer) pIDprod
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function DelProdWishlist($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("IDhash", "IDutente", "IDprod");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("DelProdWishlist", $namedArgs));
	}


	/**
	 * Service Call: GetLastWishlist
	 * Parameter options:
	 * (integer) pidStore, (integer) pidHash, (integer) pidWishlist, (integer) pIDutente, (dateTime) pdataMin
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetLastWishlist($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "dateTime");

		$parameterNames = array("idStore", "idHash", "idWishlist", "IDutente", "dataMin");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetLastWishlist", $namedArgs));
	}


	/**
	 * Service Call: GetBarcodeProd
	 * Parameter options:
	 * (integer) pidprod, (integer) pidval1, (integer) pidval2, (integer) pidval3, (string) pbarcode
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetBarcodeProd($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "string");

		$parameterNames = array("idprod", "idval1", "idval2", "idval3", "barcode");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetBarcodeProd", $namedArgs));
	}


	/**
	 * Service Call: GetQtaMinBarcodeByProd
	 * Parameter options:
	 * (integer) pidprod
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetQtaMinBarcodeByProd($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idprod");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetQtaMinBarcodeByProd", $namedArgs));
	}


	/**
	 * Service Call: GetFamiglieByStore
	 * Parameter options:
	 * (integer) pidStore, (integer) pidGruppo, (string) pslug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetFamiglieByStore($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idGruppo", "slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetFamiglieByStore", $namedArgs));
	}


	/**
	 * Service Call: GetSottoFamiglieByStore
	 * Parameter options:
	 * (integer) pidStore, (integer) pidFamiglia, (integer) pidGruppo, (string) pslug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSottoFamiglieByStore($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idFamiglia", "idGruppo", "slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSottoFamiglieByStore", $namedArgs));
	}


	/**
	 * Service Call: GetGruppiByStore
	 * Parameter options:
	 * (integer) pidStore, (string) pslug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGruppiByStore($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGruppiByStore", $namedArgs));
	}


	/**
	 * Service Call: Getfiltribyparam
	 * Parameter options:
	 * (string) pParametri, (string) plingua, (integer) putente, (integer) pmagazzino
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function Getfiltribyparam($mixed = null) {
		$validParameters = array("string", "string", "integer", "integer");

		$parameterNames = array("Parametri", "lingua", "utente", "magazzino");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("Getfiltribyparam", $namedArgs));
	}


	/**
	 * Service Call: Getprodbyparam
	 * Parameter options:
	 * (string) pParametri, (string) plingua, (integer) pUtente, (integer) pmagazzino
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function Getprodbyparam($mixed = null) {
		$validParameters = array("string", "string", "integer", "integer");

		$parameterNames = array("Parametri", "lingua", "Utente", "magazzino");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("Getprodbyparam", $namedArgs));
	}


	/**
	 * Service Call: GetprodbyparamProdfiltro
	 * Parameter options:
	 * (string) pParametri, (string) plingua, (integer) pUtente, (integer) pmagazzino
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetprodbyparamProdfiltro($mixed = null) {
		$validParameters = array("string", "string", "integer", "integer");

		$parameterNames = array("Parametri", "lingua", "Utente", "magazzino");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetprodbyparamProdfiltro", $namedArgs));
	}


	/**
	 * Service Call: GetprodbyparamNew
	 * Parameter options:
	 * (string) pParametri, (string) plingua, (integer) pUtente, (integer) pmagazzino
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetprodbyparamNew($mixed = null) {
		$validParameters = array("string", "string", "integer", "integer");

		$parameterNames = array("Parametri", "lingua", "Utente", "magazzino");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetprodbyparamNew", $namedArgs));
	}


	/**
	 * Service Call: InsertTestata
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (integer) pidDestinazione, (integer) pidCoupon, (integer) pidVettore, (integer) pidAspettoBeni, (string) prif1, (string) prif2, (string) prif3, (integer) pidListino, (integer) pidValuta, (integer) pidSoggettoAgente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertTestata($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "string", "string", "string", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idUtente", "idDestinazione", "idCoupon", "idVettore", "idAspettoBeni", "rif1", "rif2", "rif3", "idListino", "idValuta", "idSoggettoAgente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertTestata", $namedArgs));
	}


	/**
	 * Service Call: GetTestata
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestata($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestata", $namedArgs));
	}


	/**
	 * Service Call: GetTestataByHash
	 * Parameter options:
	 * (integer) pidStore, (string) phash
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestataByHash($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idStore", "hash");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestataByHash", $namedArgs));
	}


	/**
	 * Service Call: GetTestataByNumero
	 * Parameter options:
	 * (integer) pidStore, (integer) pnumero, (integer) panno
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestataByNumero($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idStore", "numero", "anno");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestataByNumero", $namedArgs));
	}


	/**
	 * Service Call: GetTestataByToken
	 * Parameter options:
	 * (integer) pidStore, (string) ptoken
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestataByToken($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idStore", "token");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestataByToken", $namedArgs));
	}


	/**
	 * Service Call: GetTestataByTokenPos
	 * Parameter options:
	 * (integer) pidStore, (string) ptoken
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestataByTokenPos($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idStore", "token");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestataByTokenPos", $namedArgs));
	}


	/**
	 * Service Call: GetTestataByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestataByUtente($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idUtente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestataByUtente", $namedArgs));
	}


	/**
	 * Service Call: UpdateTestata
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pidUtente, (integer) pidDestinazione, (integer) pidCoupon, (integer) pidVettore, (integer) pidAspettoBeni, (string) prif1, (string) prif2, (string) prif3, (integer) pidPagamento, (integer) pidSpesaSped, (double) pspesaSped, (integer) pidSoggettoStoreNegozio, (integer) pclickCollect, (integer) pidListino, (integer) pidValuta, (integer) pidSoggettoAgente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTestata($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "string", "string", "integer", "integer", "double", "integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "idUtente", "idDestinazione", "idCoupon", "idVettore", "idAspettoBeni", "rif1", "rif2", "rif3", "idPagamento", "idSpesaSped", "spesaSped", "idSoggettoStoreNegozio", "clickCollect", "idListino", "idValuta", "idSoggettoAgente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTestata", $namedArgs));
	}


	/**
	 * Service Call: UpdateTestataExt
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pidUtente, (integer) pidDestinazione, (integer) pidCoupon, (integer) pidVettore, (integer) pidAspettoBeni, (string) prif1, (string) prif2, (string) prif3, (integer) pidPagamento, (integer) pidSpesaSped, (double) pspesaSped, (integer) pidSoggettoStoreNegozio, (integer) pclickCollect, (integer) pidCausaleIva, (integer) pidListino, (integer) pidValuta, (integer) pidSoggettoAgente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTestataExt($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "string", "string", "integer", "integer", "double", "integer", "integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "idUtente", "idDestinazione", "idCoupon", "idVettore", "idAspettoBeni", "rif1", "rif2", "rif3", "idPagamento", "idSpesaSped", "spesaSped", "idSoggettoStoreNegozio", "clickCollect", "idCausaleIva", "idListino", "idValuta", "idSoggettoAgente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTestataExt", $namedArgs));
	}


	/**
	 * Service Call: UpdateTestataRif
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (string) prif1, (string) prif2, (string) prif3, (string) pnote, (string) pcustom1, (string) pcustom2, (string) pcustom3, (string) pcustom4, (string) pcustom5, (string) pcustom6, (string) pnumdocEsterno, (string) pdatadocEsterna
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTestataRif($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "rif1", "rif2", "rif3", "note", "custom1", "custom2", "custom3", "custom4", "custom5", "custom6", "numdocEsterno", "datadocEsterna");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTestataRif", $namedArgs));
	}


	/**
	 * Service Call: EvadiCarrello
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pidUtente, (integer) pidCausaleTrasporto, (string) prif1, (string) prif2, (string) prif3
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function EvadiCarrello($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "string", "string", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "idUtente", "idCausaleTrasporto", "rif1", "rif2", "rif3");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("EvadiCarrello", $namedArgs));
	}


	/**
	 * Service Call: SetDefaultSpesaSpedizione
	 * Parameter options:
	 * (integer) pidStore, (string) pprovincia, (string) pnazione, (double) ppeso, (double) pvolume, (double) ptotale, (integer) pclientePrime, (integer) pclickCollect, (string) plingua, (integer) pPorto
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetDefaultSpesaSpedizione($mixed = null) {
		$validParameters = array("integer", "string", "string", "double", "double", "double", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "provincia", "nazione", "peso", "volume", "totale", "clientePrime", "clickCollect", "lingua", "Porto");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetDefaultSpesaSpedizione", $namedArgs));
	}


	/**
	 * Service Call: GetSpesaSpedizione
	 * Parameter options:
	 * (integer) pidStore, (integer) pidSpesaSpedizione, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSpesaSpedizione($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idSpesaSpedizione", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSpesaSpedizione", $namedArgs));
	}


	/**
	 * Service Call: CalcolaSpeseDiSpedizione
	 * Parameter options:
	 * (integer) pidStore, (string) pprovincia, (string) pnazione, (double) ppeso, (double) pvolume, (double) ptotale, (integer) pprime, (integer) pclickCollect, (string) plingua, (integer) pidListino, (integer) pidUtente, (integer) pmezzoDestinatario, (string) pcap
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CalcolaSpeseDiSpedizione($mixed = null) {
		$validParameters = array("integer", "string", "string", "double", "double", "double", "integer", "integer", "string", "integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "provincia", "nazione", "peso", "volume", "totale", "prime", "clickCollect", "lingua", "idListino", "idUtente", "mezzoDestinatario", "cap");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CalcolaSpeseDiSpedizione", $namedArgs));
	}


	/**
	 * Service Call: UpdateSpesaSpedizione
	 * Parameter options:
	 * (integer) pidStore, (integer) pidSpesaSpedizione, (string) pdescrizione, (double) pvalore, (double) pprezzoDa, (double) pprezzoA, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateSpesaSpedizione($mixed = null) {
		$validParameters = array("integer", "integer", "string", "double", "double", "double", "string");

		$parameterNames = array("idStore", "idSpesaSpedizione", "descrizione", "valore", "prezzoDa", "prezzoA", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateSpesaSpedizione", $namedArgs));
	}


	/**
	 * Service Call: SetTestataPagato
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (double) pcontrovaloreEuro
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetTestataPagato($mixed = null) {
		$validParameters = array("integer", "integer", "double");

		$parameterNames = array("idStore", "idTestataDocumento", "controvaloreEuro");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetTestataPagato", $namedArgs));
	}


	/**
	 * Service Call: UpdateTestataToken
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (string) ptipoToken, (string) ptoken
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTestataToken($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "tipoToken", "token");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTestataToken", $namedArgs));
	}


	/**
	 * Service Call: SetTestataHash
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (string) phash, (dateTime) pdataCreazione, (dateTime) pdataScadenza, (string) ptipoToken, (string) ptoken
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetTestataHash($mixed = null) {
		$validParameters = array("integer", "integer", "string", "dateTime", "dateTime", "string", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "hash", "dataCreazione", "dataScadenza", "tipoToken", "token");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetTestataHash", $namedArgs));
	}


	/**
	 * Service Call: GetLastHashByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetLastHashByUtente($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idUtente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetLastHashByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetCarrelliByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (dateTime) pdataInizio, (dateTime) pdataFine, (double) pimportoMinimo, (integer) pincEvasi, (dateTime) pdataInizioHash, (dateTime) pdataFineHash, (integer) pricercaperhash
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCarrelliByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "dateTime", "dateTime", "double", "integer", "dateTime", "dateTime", "integer");

		$parameterNames = array("idStore", "idUtente", "dataInizio", "dataFine", "importoMinimo", "incEvasi", "dataInizioHash", "dataFineHash", "ricercaperhash");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCarrelliByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetTestateByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestateByUtente($mixed = null) {
		$validParameters = array("integer", "integer" , "dateTime" , "dateTime");

		$parameterNames = array("idStore", "idUtente" , "daData" , "aData");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestateByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetTestateBySoggettoAgente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidSoggettoAgente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestateBySoggettoAgente($mixed = null) {
		$validParameters = array("integer", "integer" , "dateTime" , "dateTime");

		$parameterNames = array("idStore", "idSoggettoAgente" , "daData" , "aData");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestateBySoggettoAgente", $namedArgs));
	}


	/**
	 * Service Call: ChangeTestataToDealer
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumenti, (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function ChangeTestataToDealer($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumenti", "idUtente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ChangeTestataToDealer", $namedArgs));
	}


	/**
	 * Service Call: ChangeTestataToPrivato
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function ChangeTestataToPrivato($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "idUtente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ChangeTestataToPrivato", $namedArgs));
	}


	/**
	 * Service Call: GetPagamentiByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidPagamento, (integer) pidUtente, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPagamentiByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idPagamento", "idUtente", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPagamentiByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetPagamenti
	 * Parameter options:
	 * (integer) pidStore, (integer) pidPagamento, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPagamenti($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idPagamento", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPagamenti", $namedArgs));
	}


	/**
	 * Service Call: CheckCoupon
	 * Parameter options:
	 * (integer) pidStore, (string) pcodice, (double) pspesa, (integer) pidUtente, (integer) pIdDoc, (string) pLingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckCoupon($mixed = null) {
		$validParameters = array("integer", "string", "double", "integer", "integer", "string");

		$parameterNames = array("idStore", "codice", "spesa", "idUtente", "IdDoc", "Lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckCoupon", $namedArgs));
	}


	/**
	 * Service Call: GetCoupon
	 * Parameter options:
	 * (integer) pidStore, (integer) pidCoupon, (string) pLingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCoupon($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idCoupon", "Lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCoupon", $namedArgs));
	}


	/**
	 * Service Call: GetCouponByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (string) pLingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCouponByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idUtente", "Lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCouponByUtente", $namedArgs));
	}


	/**
	 * Service Call: InsertCoupon
	 * Parameter options:
	 * (integer) pidStore, (string) pcodice, (double) psconto, (double) pscontoPercentuale, (integer) pspedizioneGratuita, (integer) pusoSingolo, (integer) pusoSingoloCliente, (double) pspesaMinima, (dateTime) pdataInizio, (dateTime) pdataFine, (integer) pidUtente, (integer) pidTestataDocumento, (string) ptipologia, (string) pIdcouponext, (integer) pidtemplate, (integer) pautomatico, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertCoupon($mixed = null) {
		$validParameters = array("integer", "string", "double", "double", "integer", "integer", "integer", "double", "dateTime", "dateTime", "integer", "integer", "string", "string", "integer", "integer", "string");

		$parameterNames = array("idStore", "codice", "sconto", "scontoPercentuale", "spedizioneGratuita", "usoSingolo", "usoSingoloCliente", "spesaMinima", "dataInizio", "dataFine", "idUtente", "idTestataDocumento", "tipologia", "Idcouponext", "idtemplate", "automatico", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertCoupon", $namedArgs));
	}


	/**
	 * Service Call: GetParametriCoupon
	 * Parameter options:
	 * (integer) pidStore, (integer) pidCoupon, (string) pcodiceCoupon
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetParametriCoupon($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idCoupon", "codiceCoupon");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetParametriCoupon", $namedArgs));
	}


	/**
	 * Service Call: SetCouponUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidCoupon, (integer) pidSoggetto, (string) pLingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetCouponUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idCoupon", "idSoggetto", "Lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetCouponUtente", $namedArgs));
	}


	/**
	 * Service Call: InsertTestataListino
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (integer) pidDestinazione, (integer) pidCoupon, (integer) pidVettore, (integer) pidAspettoBeni, (string) prif1, (string) prif2, (string) prif3, (integer) pidListino, (integer) pidValuta, (integer) pidAgente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertTestataListino($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "string", "string", "string", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idUtente", "idDestinazione", "idCoupon", "idVettore", "idAspettoBeni", "rif1", "rif2", "rif3", "idListino", "idValuta", "idAgente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertTestataListino", $namedArgs));
	}


	/**
	 * Service Call: UpdateTestataListino
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pidUtente, (integer) pidDestinazione, (integer) pidCoupon, (integer) pidVettore, (integer) pidAspettoBeni, (string) prif1, (string) prif2, (string) prif3, (integer) pidPagamento, (integer) pidSpesaSped, (double) pspesaSped, (integer) pidSoggettoStoreNegozio, (integer) pclickCollect, (integer) pidListino, (integer) pidValuta
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTestataListino($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "string", "string", "integer", "integer", "double", "integer", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "idUtente", "idDestinazione", "idCoupon", "idVettore", "idAspettoBeni", "rif1", "rif2", "rif3", "idPagamento", "idSpesaSped", "spesaSped", "idSoggettoStoreNegozio", "clickCollect", "idListino", "idValuta");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTestataListino", $namedArgs));
	}


	/**
	 * Service Call: RipetiTestata
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (string) pcheckGiacenza
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function RipetiTestata($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "checkGiacenza");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("RipetiTestata", $namedArgs));
	}


	/**
	 * Service Call: GetTestateByUtenteTipo
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (string) ptipoRecord
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestateByUtenteTipo($mixed = null) {
		$validParameters = array("integer", "integer", "string" , "dateTime" , "dateTime");

		$parameterNames = array("idStore", "idUtente", "tipoRecord", "daData", "aData");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestateByUtenteTipo", $namedArgs));
	}


	/**
	 * Service Call: EvadiCarrelloExt
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pidUtente, (integer) pidCausaleTrasporto, (string) prif1, (string) prif2, (string) prif3, (integer) pidCausaleMaga, (string) ptipoRec, (integer) pmovimenta
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function EvadiCarrelloExt($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "string", "string", "string", "integer", "string", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "idUtente", "idCausaleTrasporto", "rif1", "rif2", "rif3", "idCausaleMaga", "tipoRec", "movimenta");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("EvadiCarrelloExt", $namedArgs));
	}


	/**
	 * Service Call: UpdateTestataTipoStampa
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (string) ptipoStampa
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTestataTipoStampa($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "tipoStampa");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTestataTipoStampa", $namedArgs));
	}


	/**
	 * Service Call: UpdateTestataStato
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pconfermato, (integer) pannullato, (integer) psospeso, (dateTime) pdataConferma
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTestataStato($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "dateTime");

		$parameterNames = array("idStore", "idTestataDocumento", "confermato", "annullato", "sospeso", "dataConferma");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTestataStato", $namedArgs));
	}


	/**
	 * Service Call: GetDocumento
	 * Parameter options:
	 * (integer) pidStore, (string) pTipoRec, (integer) pCausaleMaga, (integer) pCausaleTras, (dateTime) pDataDa, (dateTime) pDataA, (integer) pNumeroDoc, (integer) pIdUtente, (integer) pIdProd
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetDocumento($mixed = null) {
		$validParameters = array("integer", "string", "integer", "integer", "dateTime", "dateTime", "integer", "integer", "integer");

		$parameterNames = array("idStore", "TipoRec", "CausaleMaga", "CausaleTras", "DataDa", "DataA", "NumeroDoc", "IdUtente", "IdProd");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetDocumento", $namedArgs));
	}


	/**
	 * Service Call: GetCarrelloByOrdine
	 * Parameter options:
	 * (integer) pidStore, (integer) pidOrdine
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCarrelloByOrdine($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idOrdine");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCarrelloByOrdine", $namedArgs));
	}


	/**
	 * Service Call: GetRighe
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetRighe($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetRighe", $namedArgs));
	}


	/**
	 * Service Call: GetRiga
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) prigaDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetRiga($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "rigaDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetRiga", $namedArgs));
	}


	/**
	 * Service Call: InsertRiga
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pidProd, (integer) pidCausaleMagazzino, (integer) pidCausaleIva, (double) pprezzo, (integer) pidValVar1, (integer) pidValVar2, (integer) pidValVar3, (integer) pidCustom1, (string) pcustom1, (integer) pidCustom2, (string) pcustom2, (integer) pidCustom3, (string) pcustom3, (integer) pidCustom4, (string) pcustom4, (integer) pidCustom5, (string) pcustom5, (double) pquantita, (string) pnote
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertRiga($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "double", "integer", "integer", "integer", "integer", "string", "integer", "string", "integer", "string", "integer", "string", "integer", "string", "double", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "idProd", "idCausaleMagazzino", "idCausaleIva", "prezzo", "idValVar1", "idValVar2", "idValVar3", "idCustom1", "custom1", "idCustom2", "custom2", "idCustom3", "custom3", "idCustom4", "custom4", "idCustom5", "custom5", "quantita", "note");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		//echo json_encode($namedArgs);
		return $this->SoapXmlDecode($this->__soapCall("InsertRiga", $namedArgs));
	}


	/**
	 * Service Call: UpdateRiga
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) prigaDocumento, (integer) pidProd, (integer) pidCausaleMagazzino, (integer) pidCausaleIva, (double) pprezzo, (integer) pidValVar1, (integer) pidValVar2, (integer) pidValVar3, (integer) pidCustom1, (string) pcustom1, (integer) pidCustom2, (string) pcustom2, (integer) pidCustom3, (string) pcustom3, (integer) pidCustom4, (string) pcustom4, (integer) pidCustom5, (string) pcustom5, (double) pquantita, (string) pnote
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateRiga($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "double", "integer", "integer", "integer", "integer", "string", "integer", "string", "integer", "string", "integer", "string", "integer", "string", "double", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "rigaDocumento", "idProd", "idCausaleMagazzino", "idCausaleIva", "prezzo", "idValVar1", "idValVar2", "idValVar3", "idCustom1", "custom1", "idCustom2", "custom2", "idCustom3", "custom3", "idCustom4", "custom4", "idCustom5", "custom5", "quantita", "note");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateRiga", $namedArgs));
	}


	/**
	 * Service Call: CheckRighe
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (string) pcheckGiacenza
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckRighe($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "checkGiacenza");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckRighe", $namedArgs));
	}


	/**
	 * Service Call: DeleteRiga
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) prigaDocumento, (integer) pmantieniTestata
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function DeleteRiga($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "rigaDocumento", "mantieniTestata");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("DeleteRiga", $namedArgs));
	}


	/**
	 * Service Call: DeleteRigaOld
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) prigaDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function DeleteRigaOld($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento", "rigaDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("DeleteRigaOld", $namedArgs));
	}


	/**
	 * Service Call: CastellettoIva
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CastellettoIva($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idTestataDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CastellettoIva", $namedArgs));
	}


	/**
	 * Service Call: OttieniStampa
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (integer) pnumeroDocumento, (dateTime) pdataDocumento, (string) pcodiceStampa
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function OttieniStampa($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "dateTime", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "numeroDocumento", "dataDocumento", "codiceStampa");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("OttieniStampa", $namedArgs));
	}


	/**
	 * Service Call: GetFatturaByOrdine
	 * Parameter options:
	 * (integer) pidTestataDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetFatturaByOrdine($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idTestataDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetFatturaByOrdine", $namedArgs));
	}


	/**
	 * Service Call: GetConfig
	 * Parameter options:
	 * (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetConfig($mixed = null) {
		$validParameters = array("string");

		$parameterNames = array("lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetConfig", $namedArgs));
	}


	/**
	 * Service Call: SetHash
	 * Parameter options:
	 * (string) pHash, (dateTime) pdatacreazione, (dateTime) pdatascadenza
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetHash($mixed = null) {
		$validParameters = array("string", "dateTime", "dateTime");

		$parameterNames = array("Hash", "datacreazione", "datascadenza");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetHash", $namedArgs));
	}


	/**
	 * Service Call: SetHashTestata
	 * Parameter options:
	 * (integer) pidTestata, (string) pHash, (dateTime) pdatacreazione, (dateTime) pdatascadenza
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetHashTestata($mixed = null) {
		$validParameters = array("integer", "string", "dateTime", "dateTime");

		$parameterNames = array("idTestata", "Hash", "datacreazione", "datascadenza");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetHashTestata", $namedArgs));
	}


	/**
	 * Service Call: UpdateHash
	 * Parameter options:
	 * (string) pHash, (dateTime) pdatacreazione, (dateTime) pdatascadenza
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateHash($mixed = null) {
		$validParameters = array("string", "dateTime", "dateTime");

		$parameterNames = array("Hash", "datacreazione", "datascadenza");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateHash", $namedArgs));
	}


	/**
	 * Service Call: CheckServices
	 * Parameter options:
	 * (integer) pcheckDb
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckServices($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("checkDb");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckServices", $namedArgs));
	}


	/**
	 * Service Call: GetCausaliIva
	 * Parameter options:

	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCausaliIva($mixed = null) {
		$validParameters = array("");

		$args = $this->_checkArguments(func_get_args(), $validParameters);
		return $this->SoapXmlDecode($this->__soapCall("GetCausaliIva", $args));
	}


	/**
	 * Service Call: GetStore
	 * Parameter options:
	 * (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetStore($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetStore", $namedArgs));
	}


	/**
	 * Service Call: GetFotoPrimopiano
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDfotoprimopiano, (string) plingua, (integer) pIdcatalbero
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetFotoPrimopiano($mixed = null) {
		$validParameters = array("integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "IDfotoprimopiano", "lingua", "Idcatalbero");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetFotoPrimopiano", $namedArgs));
	}


	/**
	 * Service Call: GetBanner
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDbanner, (integer) pidBannerCat, (integer) pidSesso, (integer) pidEta, (integer) pidCat, (integer) pidLookbook, (string) plingua, (integer) pidcatAlbero
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetBanner($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "IDbanner", "idBannerCat", "idSesso", "idEta", "idCat", "idLookbook", "lingua", "idcatAlbero");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetBanner", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaBanner
	 * Parameter options:
	 * (integer) pIDcat, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaBanner($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("IDcat", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaBanner", $namedArgs));
	}


	/**
	 * Service Call: GetLookbook
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDlookbook, (integer) pidlbCat, (integer) pidSesso, (integer) pidStagione, (integer) pidMarchio, (integer) pidcat, (string) plingua, (integer) pidTema
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetLookbook($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "IDlookbook", "idlbCat", "idSesso", "idStagione", "idMarchio", "idcat", "lingua", "idTema");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetLookbook", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaLookbook
	 * Parameter options:
	 * (integer) pidStore, (integer) pidStagione, (integer) pIDcat, (string) pslug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaLookbook($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idStagione", "IDcat", "slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaLookbook", $namedArgs));
	}


	/**
	 * Service Call: GetProdByLookbook
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDlookbook
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProdByLookbook($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "IDlookbook");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetProdByLookbook", $namedArgs));
	}


	/**
	 * Service Call: GetBlog
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) pslug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetBlog($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "ID", "slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetBlog", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaBlog
	 * Parameter options:
	 * (integer) pidStore, (integer) pidBlog, (integer) pid, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaBlog($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idBlog", "id", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaBlog", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaBlogBySlug
	 * Parameter options:
	 * (integer) pidStore, (integer) pidBlog, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaBlogBySlug($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idBlog", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaBlogBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetSottocategoriaBlog
	 * Parameter options:
	 * (integer) pidStore, (integer) pidCat, (integer) pid, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSottocategoriaBlog($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idCat", "id", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSottocategoriaBlog", $namedArgs));
	}


	/**
	 * Service Call: GetSottocategoriaBlogBySlug
	 * Parameter options:
	 * (integer) pidStore, (integer) pidCat, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSottocategoriaBlogBySlug($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idCat", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSottocategoriaBlogBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetTagBlog
	 * Parameter options:
	 * (integer) pidStore, (integer) pidBlog, (integer) pid, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTagBlog($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idBlog", "id", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTagBlog", $namedArgs));
	}


	/**
	 * Service Call: GetTagBlogBySlug
	 * Parameter options:
	 * (integer) pidStore, (integer) pidBlog, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTagBlogBySlug($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idBlog", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTagBlogBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetAutoreBlog
	 * Parameter options:
	 * (integer) pidStore, (integer) pid, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetAutoreBlog($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "id", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetAutoreBlog", $namedArgs));
	}


	/**
	 * Service Call: GetAutoreBlogBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetAutoreBlogBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetAutoreBlogBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetPost
	 * Parameter options:
	 * (integer) pidStore, (integer) pidBlog, (integer) pidCat, (integer) pidSottocat, (integer) pidTag, (integer) pidAutore, (integer) pID, (string) pslug, (string) plingua, (integer) plimit, (integer) pHome, (integer) pidProd
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPost($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "string", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idBlog", "idCat", "idSottocat", "idTag", "idAutore", "ID", "slug", "lingua", "limit", "Home", "idProd");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPost", $namedArgs));
	}


	/**
	 * Service Call: GetSeo
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDprod, (integer) pIDsesso, (integer) pIDeta, (integer) pIDcategoria, (integer) pIDmarchio, (integer) pIDcorr, (integer) pIDtema, (integer) pIDevento, (integer) pIDnodo, (integer) pidfamiglia, (integer) pidgruppo, (integer) pidsottofamiglia, (string) plingua, (integer) pidSottoCategoria
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSeo($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "IDprod", "IDsesso", "IDeta", "IDcategoria", "IDmarchio", "IDcorr", "IDtema", "IDevento", "IDnodo", "idfamiglia", "idgruppo", "idsottofamiglia", "lingua", "idSottoCategoria");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSeo", $namedArgs));
	}


	/**
	 * Service Call: GetCategorieNews
	 * Parameter options:
	 * (integer) pidstore, (integer) pIDCategoria, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategorieNews($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idstore", "IDCategoria", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategorieNews", $namedArgs));
	}


	/**
	 * Service Call: GetCategorieNewsBySlug
	 * Parameter options:
	 * (integer) pidstore, (string) pslug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategorieNewsBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idstore", "slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategorieNewsBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetNews
	 * Parameter options:
	 * (integer) pidstore, (integer) pIDNews, (integer) pIDCategoria, (integer) plimite, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetNews($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "string");

		$parameterNames = array("idstore", "IDNews", "IDCategoria", "limite", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetNews", $namedArgs));
	}


	/**
	 * Service Call: GetGuestbook
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDguestbook, (integer) plimit, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGuestbook($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "IDguestbook", "limit", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGuestbook", $namedArgs));
	}


	/**
	 * Service Call: InsertGuestbook
	 * Parameter options:
	 * (integer) pidStore, (string) pnome, (string) pemail, (string) pmessaggio, (string) pip
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertGuestbook($mixed = null) {
		$validParameters = array("integer", "string", "string", "string", "string");

		$parameterNames = array("idStore", "nome", "email", "messaggio", "ip");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertGuestbook", $namedArgs));
	}


	/**
	 * Service Call: GetUtente
	 * Parameter options:
	 * (integer) pidutente, (integer) pIDStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtente($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idutente", "IDStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtente", $namedArgs));
	}


	/**
	 * Service Call: GetUtenteByEmail
	 * Parameter options:
	 * (string) pemail, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtenteByEmail($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("email", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtenteByEmail", $namedArgs));
	}


	/**
	 * Service Call: GetUtenteByHash
	 * Parameter options:
	 * (string) phash, (integer) pIdStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtenteByHash($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("hash", "IdStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtenteByHash", $namedArgs));
	}


	/**
	 * Service Call: GetUtenteByPartitaIva
	 * Parameter options:
	 * (string) ppiva, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtenteByPartitaIva($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("piva", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtenteByPartitaIva", $namedArgs));
	}


	/**
	 * Service Call: TryLogin
	 * Parameter options:
	 * (string) pemail, (string) ppassword, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function TryLogin($mixed = null) {
		$validParameters = array("string", "string", "integer");

		$parameterNames = array("email", "password", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("TryLogin", $namedArgs));
	}


	/**
	 * Service Call: TryLoginExt
	 * Parameter options:
	 * (string) pemail, (string) ppassword, (integer) ptipoutente, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function TryLoginExt($mixed = null) {
		$validParameters = array("string", "string", "integer", "integer");

		$parameterNames = array("email", "password", "tipoutente", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("TryLoginExt", $namedArgs));
	}


	/**
	 * Service Call: TryLoginGuest
	 * Parameter options:
	 * (string) pemail, (integer) pIdStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function TryLoginGuest($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("email", "IdStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("TryLoginGuest", $namedArgs));
	}


	/**
	 * Service Call: CheckUser
	 * Parameter options:
	 * (string) pemail, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckUser($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("email", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckUser", $namedArgs));
	}


	/**
	 * Service Call: CheckUserExt
	 * Parameter options:
	 * (string) pemail, (integer) ptipoutente, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckUserExt($mixed = null) {
		$validParameters = array("string", "integer", "integer");

		$parameterNames = array("email", "tipoutente", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckUserExt", $namedArgs));
	}


	/**
	 * Service Call: Sethashutente
	 * Parameter options:
	 * (integer) pidutente, (string) pHash, (dateTime) pdatacreazione, (dateTime) pdatascadenza
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function Sethashutente($mixed = null) {
		$validParameters = array("integer", "string", "dateTime", "dateTime");

		$parameterNames = array("idutente", "Hash", "datacreazione", "datascadenza");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("Sethashutente", $namedArgs));
	}


	/**
	 * Service Call: ID_ReceiveFile
	 * Parameter options:
	 * (base64Binary) FileData, (string) Extension
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function ID_ReceiveFile($mixed = null) {
		$validParameters = array("base64Binary", "string");

		$parameterNames = array("FileData", "Extension");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ID_ReceiveFile", $namedArgs));
	}


	/**
	 * Service Call: GetSottocategoriaBySlug
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDCategoria, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSottocategoriaBySlug($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "IDCategoria", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSottocategoriaBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaBySesso
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaBySesso($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaBySesso", $namedArgs));
	}


	/**
	 * Service Call: ID_SendFile
	 * Parameter options:
	 * (string) FileName
	 * @param mixed,... See function description for parameter options
	 * @return base64Binary
	 * @throws Exception invalid function signature message
	 */
	public function ID_SendFile($mixed = null) {
		$validParameters = array("string");

		$parameterNames = array("FileName");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ID_SendFile", $namedArgs));
	}


	/**
	 * Service Call: GetCategoria
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoria($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoria", $namedArgs));
	}


	/**
	 * Service Call: GetGuidaTaglia
	 * Parameter options:
	 * (integer) pIDprod, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGuidaTaglia($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("IDprod", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGuidaTaglia", $namedArgs));
	}


	/**
	 * Service Call: GetGuidaTagliaEta
	 * Parameter options:
	 * (integer) pidstore, (integer) pIDprod, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGuidaTagliaEta($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idstore", "IDprod", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGuidaTagliaEta", $namedArgs));
	}


	/**
	 * Service Call: GetCompoLavaggiunoar
	 * Parameter options:
	 * (integer) pIdprod, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCompoLavaggiunoar($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("Idprod", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCompoLavaggiunoar", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaByEvento
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaByEvento($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaByEvento", $namedArgs));
	}


	/**
	 * Service Call: CheckUserExist
	 * Parameter options:
	 * (string) pemail, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckUserExist($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("email", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckUserExist", $namedArgs));
	}


	/**
	 * Service Call: InsertUtente
	 * Parameter options:
	 * (integer) pidStore, (string) pazienda, (string) pnome, (string) pcognome, (string) pemail, (string) ppassword, (string) pnazione, (string) pindirizzo, (string) pcivico, (string) pcap, (string) pcitta, (string) pprovincia, (string) ptelefono, (string) pragionesociale, (string) ppiva, (string) pcodfisc, (string) pcodfiscazienda, (string) ppersonariferimento, (string) pemailriferimento, (integer) ptipo, (integer) pidtestalistini, (integer) pcausaleiva, (integer) pcausalepagamenti, (integer) pattivo, (integer) pidStorePreferito, (string) pcodicePrime, (string) pfidelity, (string) pLingua, (string) pTipoAffiliazione, (integer) ptipoaccount, (integer) priceviemaildocumento, (string) pcellulare, (string) pfax, (string) pnotachiusura, (string) pSDI, (string) pPEC
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertUtente($mixed = null) {
		$validParameters = array("integer", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "integer", "integer", "integer", "integer", "integer", "integer", "string", "string", "string", "string", "integer", "integer", "string", "string", "string", "string", "string");

		$parameterNames = array("idStore", "azienda", "nome", "cognome", "email", "password", "nazione", "indirizzo", "civico", "cap", "citta", "provincia", "telefono", "ragionesociale", "piva", "codfisc", "codfiscazienda", "personariferimento", "emailriferimento", "tipo", "idtestalistini", "causaleiva", "causalepagamenti", "attivo", "idStorePreferito", "codicePrime", "fidelity", "Lingua", "TipoAffiliazione", "tipoaccount", "riceviemaildocumento", "cellulare", "fax", "notachiusura", "SDI", "PEC");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertUtente", $namedArgs));
	}


	/**
	 * Service Call: UpdateUtente
	 * Parameter options:
	 * (integer) pidStore, (string) pazienda, (string) pnome, (string) pcognome, (string) pemail, (string) ppassword, (string) pnazione, (string) pindirizzo, (string) pcivico, (string) pcap, (string) pcitta, (string) pprovincia, (string) ptelefono, (string) pragionesociale, (string) ppiva, (string) pcodfisc, (string) pcodfiscazienda, (string) ppersonariferimento, (string) pemailriferimento, (integer) pidtestalistini, (integer) pcausaleiva, (integer) pcausalepagamenti, (integer) pid, (integer) pidStorePreferito, (string) pcodicePrime, (string) pfidelity, (string) pTipoAffiliazione, (string) pcellulare, (string) pfax, (string) pnotachiusura, (string) pSDI, (string) pPEC
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateUtente($mixed = null) {
		$validParameters = array("integer", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "integer", "integer", "integer", "integer", "integer", "string", "string", "string", "string", "string", "string", "string", "string");

		$parameterNames = array("idStore", "azienda", "nome", "cognome", "email", "password", "nazione", "indirizzo", "civico", "cap", "citta", "provincia", "telefono", "ragionesociale", "piva", "codfisc", "codfiscazienda", "personariferimento", "emailriferimento", "idtestalistini", "causaleiva", "causalepagamenti", "id", "idStorePreferito", "codicePrime", "fidelity", "TipoAffiliazione", "cellulare", "fax", "notachiusura", "SDI", "PEC");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateUtente", $namedArgs));
	}


	/**
	 * Service Call: UpdateUtenteInfoAgg
	 * Parameter options:
	 * (integer) pid, (string) platitudine, (string) plongitudine, (string) psitoweb, (integer) pflagcustom1, (integer) pflagcustom2, (integer) pflagcustom3, (integer) pflagcustom4, (integer) pflagcustom5, (integer) pidRegione, (integer) pcodiceAgente, (integer) pidvaluta, (string) pdocumentoDefault, (integer) pstampaPreferita, (dateTime) pdataprimologin
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateUtenteInfoAgg($mixed = null) {
		$validParameters = array("integer", "string", "string", "string", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "integer", "dateTime");

		$parameterNames = array("id", "latitudine", "longitudine", "sitoweb", "flagcustom1", "flagcustom2", "flagcustom3", "flagcustom4", "flagcustom5", "idRegione", "codiceAgente", "idvaluta", "documentoDefault", "stampaPreferita", "dataprimologin");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateUtenteInfoAgg", $namedArgs));
	}


	/**
	 * Service Call: Crypt
	 * Parameter options:
	 * (string) ppass, (string) pChiave
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function Crypt($mixed = null) {
		$validParameters = array("string", "string");

		$parameterNames = array("pass", "Chiave");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("Crypt", $namedArgs));
	}


	/**
	 * Service Call: SetResetPwd
	 * Parameter options:
	 * (string) pemail, (string) phash, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetResetPwd($mixed = null) {
		$validParameters = array("string", "string", "integer");

		$parameterNames = array("email", "hash", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetResetPwd", $namedArgs));
	}


	/**
	 * Service Call: SetResetPwdExt
	 * Parameter options:
	 * (string) pemail, (string) phash, (integer) ptipoutente, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SetResetPwdExt($mixed = null) {
		$validParameters = array("string", "string", "integer", "integer");

		$parameterNames = array("email", "hash", "tipoutente", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SetResetPwdExt", $namedArgs));
	}


	/**
	 * Service Call: ResetPwd
	 * Parameter options:
	 * (string) phash, (string) pnuovapassword
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function ResetPwd($mixed = null) {
		$validParameters = array("string", "string");

		$parameterNames = array("hash", "nuovapassword");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ResetPwd", $namedArgs));
	}


	/**
	 * Service Call: ResetPwdExt
	 * Parameter options:
	 * (string) phash, (string) pnuovapassword, (integer) ptipoutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function ResetPwdExt($mixed = null) {
		$validParameters = array("string", "string", "integer");

		$parameterNames = array("hash", "nuovapassword", "tipoutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ResetPwdExt", $namedArgs));
	}


	/**
	 * Service Call: AttivaUtente
	 * Parameter options:
	 * (integer) pidutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function AttivaUtente($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("AttivaUtente", $namedArgs));
	}


	/**
	 * Service Call: DisattivaUtente
	 * Parameter options:
	 * (integer) pidutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function DisattivaUtente($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("DisattivaUtente", $namedArgs));
	}


	/**
	 * Service Call: GetDestinazione
	 * Parameter options:
	 * (integer) pidStore, (integer) piddest, (integer) pidutente, (string) pidpudo
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetDestinazione($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "iddest", "idutente", "idpudo");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetDestinazione", $namedArgs));
	}


	/**
	 * Service Call: InsertDestinazione
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDUtente, (string) pdesc1, (string) pdesc2, (string) pindirizzo, (string) pcivico, (string) pcap, (string) pcitta, (string) pprovincia, (string) pnazione, (integer) pIdStorePreferito, (integer) pTipodestinazione, (string) pCitofono, (string) pInterno, (string) pScala, (string) pSDI, (string) pPEC, (string) ptelefono1, (string) ptelefono2, (string) pidpudo
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertDestinazione($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string", "string", "string", "string", "string", "string", "string", "integer", "integer", "string", "string", "string", "string", "string", "string", "string", "string");

		$parameterNames = array("idStore", "IDUtente", "desc1", "desc2", "indirizzo", "civico", "cap", "citta", "provincia", "nazione", "IdStorePreferito", "Tipodestinazione", "Citofono", "Interno", "Scala", "SDI", "PEC", "telefono1", "telefono2", "idpudo");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertDestinazione", $namedArgs));
	}


	/**
	 * Service Call: UpdateDestinazione
	 * Parameter options:
	 * (integer) pidStore, (integer) pidutente, (integer) pdestinazione, (string) pdesc1, (string) pdesc2, (string) pindirizzo, (string) pcivico, (string) pcap, (string) pcitta, (string) pprovincia, (string) pnazione, (integer) pIdStorePreferito, (string) pCitofono, (string) pInterno, (string) pScala, (string) pSDI, (string) pPEC, (string) ptelefono1, (string) ptelefono2, (string) pidpudo
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateDestinazione($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "string", "string", "string", "string", "string", "string", "string", "integer", "string", "string", "string", "string", "string", "string", "string", "string");

		$parameterNames = array("idStore", "idutente", "destinazione", "desc1", "desc2", "indirizzo", "civico", "cap", "citta", "provincia", "nazione", "IdStorePreferito", "Citofono", "Interno", "Scala", "SDI", "PEC", "telefono1", "telefono2", "idpudo");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateDestinazione", $namedArgs));
	}


	/**
	 * Service Call: GetInfo
	 * Parameter options:
	 * (integer) pidutente, (integer) pgruppo, (string) pchiave
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetInfo($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idutente", "gruppo", "chiave");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetInfo", $namedArgs));
	}


	/**
	 * Service Call: InsertInfo
	 * Parameter options:
	 * (integer) pidutente, (integer) pidgruppo, (string) pchiave, (string) pvalore, (integer) pcanUpdate
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertInfo($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string", "integer");

		$parameterNames = array("idutente", "idgruppo", "chiave", "valore", "canUpdate");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertInfo", $namedArgs));
	}


	/**
	 * Service Call: GetInfoGruppo
	 * Parameter options:
	 * (integer) pidutente, (integer) pidgruppo
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetInfoGruppo($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idutente", "idgruppo");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetInfoGruppo", $namedArgs));
	}


	/**
	 * Service Call: GetInfoGruppoBySlug
	 * Parameter options:
	 * (integer) pidutente, (string) psluggruppo
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetInfoGruppoBySlug($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idutente", "sluggruppo");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetInfoGruppoBySlug", $namedArgs));
	}


	/**
	 * Service Call: InsertInfoGruppo
	 * Parameter options:
	 * (integer) pidutente, (string) pnome
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertInfoGruppo($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idutente", "nome");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertInfoGruppo", $namedArgs));
	}


	/**
	 * Service Call: UpdateInfoGruppo
	 * Parameter options:
	 * (integer) pidutente, (integer) pidgruppo, (string) pnome
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateInfoGruppo($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idutente", "idgruppo", "nome");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateInfoGruppo", $namedArgs));
	}


	/**
	 * Service Call: GetUtentiByInfo
	 * Parameter options:
	 * (integer) pidStore, (string) pchiave, (string) pvalore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtentiByInfo($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "chiave", "valore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtentiByInfo", $namedArgs));
	}


	/**
	 * Service Call: GetUtenti
	 * Parameter options:
	 * (integer) pidStore, (integer) pidutente, (integer) pTipoUtente, (string) pcodNazione
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtenti($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idutente", "TipoUtente", "codNazione");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtenti", $namedArgs));
	}


	/**
	 * Service Call: GetUtentiByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidutente, (integer) pTipoUtente, (string) pcodNazione, (integer) pidutenteCollegato
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtentiByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "idutente", "TipoUtente", "codNazione", "idutenteCollegato");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtentiByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetPuntiVendita
	 * Parameter options:
	 * (integer) pidStore, (integer) pid, (integer) pTipoUtente, (string) pcodNazione, (integer) ppuntoVendita, (integer) pclickCollect, (integer) pmonoMarca, (string) pProvincia, (string) pCitta, (string) pCap, (integer) pIdCategoria, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPuntiVendita($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "integer", "integer", "integer", "string", "string", "string", "integer", "string");

		$parameterNames = array("idStore", "id", "TipoUtente", "codNazione", "puntoVendita", "clickCollect", "monoMarca", "Provincia", "Citta", "Cap", "IdCategoria", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPuntiVendita", $namedArgs));
	}


	/**
	 * Service Call: GetPuntiVenditaByCodice
	 * Parameter options:
	 * (integer) pidStore, (string) pcodice, (integer) pTipoUtente, (string) pcodNazione
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPuntiVenditaByCodice($mixed = null) {
		$validParameters = array("integer", "string", "integer", "string");

		$parameterNames = array("idStore", "codice", "TipoUtente", "codNazione");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPuntiVenditaByCodice", $namedArgs));
	}


	/**
	 * Service Call: GetPuntiVenditaByNegozio
	 * Parameter options:
	 * (integer) pidStore, (string) pcodiceNegozio, (integer) pTipoUtente, (string) pcodNazione
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPuntiVenditaByNegozio($mixed = null) {
		$validParameters = array("integer", "string", "integer", "string");

		$parameterNames = array("idStore", "codiceNegozio", "TipoUtente", "codNazione");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPuntiVenditaByNegozio", $namedArgs));
	}


	/**
	 * Service Call: GetPuntiVenditaByAgente
	 * Parameter options:
	 * (integer) pidStore, (integer) pagente, (integer) pTipoUtente, (string) pcodNazione
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPuntiVenditaByAgente($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "agente", "TipoUtente", "codNazione");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPuntiVenditaByAgente", $namedArgs));
	}


	/**
	 * Service Call: GetNoteUtente
	 * Parameter options:
	 * (integer) pidutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetNoteUtente($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetNoteUtente", $namedArgs));
	}


	/**
	 * Service Call: InsertCalendario
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtenti, (integer) pgiornoSettimana, (dateTime) poraInizio, (dateTime) poraFine, (integer) pindiceTurno, (dateTime) pdataEccezione, (integer) pchiuso, (string) pnota
	 * @param mixed,... See function description for parameter options
	 * @return int
	 * @throws Exception invalid function signature message
	 */
	public function InsertCalendario($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "dateTime", "dateTime", "integer", "dateTime", "integer", "string");

		$parameterNames = array("idStore", "idUtenti", "giornoSettimana", "oraInizio", "oraFine", "indiceTurno", "dataEccezione", "chiuso", "nota");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertCalendario", $namedArgs));
	}


	/**
	 * Service Call: InsertRecensioneSogg
	 * Parameter options:
	 * (integer) psoggetto, (string) pnominativo, (string) ptesto, (integer) pvoto, (dateTime) pdata, (integer) pabilitato, (string) pemail, (string) ptitolo, (integer) pidCliente, (integer) pidTestataDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertRecensioneSogg($mixed = null) {
		$validParameters = array("integer", "string", "string", "integer", "dateTime", "integer", "string", "string", "integer", "integer");

		$parameterNames = array("soggetto", "nominativo", "testo", "voto", "data", "abilitato", "email", "titolo", "idCliente", "idTestataDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertRecensioneSogg", $namedArgs));
	}


	/**
	 * Service Call: GetRecensioneSoggetto
	 * Parameter options:
	 * (integer) pidStore, (integer) pidSoggetto, (integer) pidRecensione, (integer) pidCliente, (integer) pidTestataDocumento
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetRecensioneSoggetto($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("idStore", "idSoggetto", "idRecensione", "idCliente", "idTestataDocumento");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetRecensioneSoggetto", $namedArgs));
	}


	/**
	 * Service Call: GetCategorieSoggetto
	 * Parameter options:
	 * (integer) pidStore, (integer) pidCategoria, (integer) pidSoggetto, (string) pslugCatgoria
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategorieSoggetto($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idCategoria", "idSoggetto", "slugCatgoria");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategorieSoggetto", $namedArgs));
	}


	/**
	 * Service Call: InsertCategorieSoggettoUtente
	 * Parameter options:
	 * (integer) pidSoggetto, (integer) pidcategoria, (integer) pPriorita
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertCategorieSoggettoUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idSoggetto", "idcategoria", "Priorita");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertCategorieSoggettoUtente", $namedArgs));
	}


	/**
	 * Service Call: UpdateCategorieSoggettoUtente
	 * Parameter options:
	 * (integer) pidSoggetto, (integer) pidcategoria, (integer) pPriorita
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateCategorieSoggettoUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idSoggetto", "idcategoria", "Priorita");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateCategorieSoggettoUtente", $namedArgs));
	}


	/**
	 * Service Call: DeleteCategoriaSoggettoUtente
	 * Parameter options:
	 * (integer) pidSoggetto, (integer) pidcategoria
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function DeleteCategoriaSoggettoUtente($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idSoggetto", "idcategoria");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("DeleteCategoriaSoggettoUtente", $namedArgs));
	}


	/**
	 * Service Call: GetUtenteByAgente
	 * Parameter options:
	 * (integer) pagente, (integer) pIdStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtenteByAgente($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("agente", "IdStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtenteByAgente", $namedArgs));
	}


	/**
	 * Service Call: InsertLogAzioneUtente
	 * Parameter options:
	 * (integer) pidutente, (integer) pidazione, (dateTime) pdata, (string) pcap, (string) plat, (string) plon, (string) pip, (string) pua, (string) pnazione, (string) pcitta, (string) pref1, (string) pref2, (string) pref3, (integer) pgeocoded
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertLogAzioneUtente($mixed = null) {
		$validParameters = array("integer", "integer", "dateTime", "string", "string", "string", "string", "string", "string", "string", "string", "string", "string", "integer");

		$parameterNames = array("idutente", "idazione", "data", "cap", "lat", "lon", "ip", "ua", "nazione", "citta", "ref1", "ref2", "ref3", "geocoded");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertLogAzioneUtente", $namedArgs));
	}


	/**
	 * Service Call: GetUtentiPermessi
	 * Parameter options:
	 * (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtentiPermessi($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idUtente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtentiPermessi", $namedArgs));
	}


	/**
	 * Service Call: GetPrezziUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (integer) pidListino, (string) pIdProdList, (integer) pidMarchio
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPrezziUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "idUtente", "idListino", "IdProdList", "idMarchio");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPrezziUtente", $namedArgs));
	}


	/**
	 * Service Call: GetSoggettiEmailPostVendita
	 * Parameter options:
	 * (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSoggettiEmailPostVendita($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSoggettiEmailPostVendita", $namedArgs));
	}


	/**
	 * Service Call: UtenteDOAction
	 * Parameter options:
	 * (integer) pid
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UtenteDOAction($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("id");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UtenteDOAction", $namedArgs));
	}


	/**
	 * Service Call: UpdateTipoAccount
	 * Parameter options:
	 * (integer) pid, (integer) ptipoaccount, (integer) pIdStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateTipoAccount($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("id", "tipoaccount", "IdStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateTipoAccount", $namedArgs));
	}


	/**
	 * Service Call: UpdateUtenteValoriCustom
	 * Parameter options:
	 * (integer) pid, (string) pcustom1, (string) pcustom2, (string) pcustom3, (string) pcustom4, (string) pcustom5, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateUtenteValoriCustom($mixed = null) {
		$validParameters = array("integer", "string", "string", "string", "string", "string", "integer");

		$parameterNames = array("id", "custom1", "custom2", "custom3", "custom4", "custom5", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateUtenteValoriCustom", $namedArgs));
	}


	/**
	 * Service Call: GetUtentiByAgente
	 * Parameter options:
	 * (integer) pcodAgente, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtentiByAgente($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("codAgente", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtentiByAgente", $namedArgs));
	}


	/**
	 * Service Call: UpdateUtenteInfoAgg2
	 * Parameter options:
	 * (integer) pid, (string) platitudine, (string) plongitudine, (string) psitoweb, (integer) pflagcustom1, (integer) pflagcustom2, (integer) pflagcustom3, (integer) pflagcustom4, (integer) pflagcustom5, (integer) pidRegione, (integer) pcodiceAgente, (integer) pidvaluta, (string) pdocumentoDefault, (integer) pstampaPreferita, (double) pImpMinOrdine, (double) pEtdDeadLine, (double) pEtdDeadLine2, (double) pDistMassima, (string) pInformazioni, (string) pDettagli
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function UpdateUtenteInfoAgg2($mixed = null) {
		$validParameters = array("integer", "string", "string", "string", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "string", "integer", "double", "double", "double", "double", "string", "string");

		$parameterNames = array("id", "latitudine", "longitudine", "sitoweb", "flagcustom1", "flagcustom2", "flagcustom3", "flagcustom4", "flagcustom5", "idRegione", "codiceAgente", "idvaluta", "documentoDefault", "stampaPreferita", "ImpMinOrdine", "EtdDeadLine", "EtdDeadLine2", "DistMassima", "Informazioni", "Dettagli");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("UpdateUtenteInfoAgg2", $namedArgs));
	}


	/**
	 * Service Call: SpedisciOrdineContabEcomm3
	 * Parameter options:
	 * (integer) pNumeroDoc, (dateTime) pDataDoc, (string) pTracking, (string) pnumeroContabile, (string) pdataContabile
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function SpedisciOrdineContabEcomm3($mixed = null) {
		$validParameters = array("integer", "dateTime", "string", "string", "string");

		$parameterNames = array("NumeroDoc", "DataDoc", "Tracking", "numeroContabile", "dataContabile");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("SpedisciOrdineContabEcomm3", $namedArgs));
	}


	/**
	 * Service Call: AutorizzaResoEcomm3
	 * Parameter options:
	 * (string) pbcReso
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function AutorizzaResoEcomm3($mixed = null) {
		$validParameters = array("string");

		$parameterNames = array("bcReso");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("AutorizzaResoEcomm3", $namedArgs));
	}


	/**
	 * Service Call: ImpostaOrdineEvasoEcomm3
	 * Parameter options:
	 * (integer) pIdOrdineExtOld, (integer) pIdOrdineExtNew
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function ImpostaOrdineEvasoEcomm3($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("IdOrdineExtOld", "IdOrdineExtNew");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ImpostaOrdineEvasoEcomm3", $namedArgs));
	}


	/**
	 * Service Call: EvadiOrdineEcomm3
	 * Parameter options:
	 * (integer) pNumeroDoc, (dateTime) pDataDoc
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function EvadiOrdineEcomm3($mixed = null) {
		$validParameters = array("integer", "dateTime");

		$parameterNames = array("NumeroDoc", "DataDoc");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("EvadiOrdineEcomm3", $namedArgs));
	}


	/**
	 * Service Call: EvadiOrdineEcomm3Old
	 * Parameter options:
	 * (integer) pNumeroDoc, (dateTime) pDataDoc
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function EvadiOrdineEcomm3Old($mixed = null) {
		$validParameters = array("integer", "dateTime");

		$parameterNames = array("NumeroDoc", "DataDoc");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("EvadiOrdineEcomm3Old", $namedArgs));
	}


	/**
	 * Service Call: GetMenuEta
	 * Parameter options:
	 * (integer) pidStore, (string) pLingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetMenuEta($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idStore", "Lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetMenuEta", $namedArgs));
	}


	/**
	 * Service Call: GetMenuMarchi
	 * Parameter options:
	 * (integer) pidStore, (string) pLingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetMenuMarchi($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idStore", "Lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetMenuMarchi", $namedArgs));
	}


	/**
	 * Service Call: GetMotivazioni
	 * Parameter options:
	 * (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetMotivazioni($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetMotivazioni", $namedArgs));
	}


	/**
	 * Service Call: InsertReso
	 * Parameter options:
	 * (integer) pidStore, (integer) pidTestataDocumento, (string) pragioneSociale, (string) pindirizzo, (string) pcap, (string) plocalita, (string) pprovincia, (string) pnazione, (string) preferente, (string) ptelefono, (dateTime) pdataOraRitiro, (string) pemail, (string) pcellulare, (string) piban, (string) pNote, (string) pOrario
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertReso($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string", "string", "string", "string", "string", "string", "string", "dateTime", "string", "string", "string", "string", "string");

		$parameterNames = array("idStore", "idTestataDocumento", "ragioneSociale", "indirizzo", "cap", "localita", "provincia", "nazione", "referente", "telefono", "dataOraRitiro", "email", "cellulare", "iban", "Note", "Orario");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertReso", $namedArgs));
	}


	/**
	 * Service Call: InsertRigaReso
	 * Parameter options:
	 * (integer) pidStore, (integer) pidReso, (double) pqtaResa, (string) pcodiceMotivo, (integer) pidTestataOrdine, (integer) prigaOrdine, (string) pnota, (integer) pCausaleReso
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertRigaReso($mixed = null) {
		$validParameters = array("integer", "integer", "double", "string", "integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "idReso", "qtaResa", "codiceMotivo", "idTestataOrdine", "rigaOrdine", "nota", "CausaleReso");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertRigaReso", $namedArgs));
	}


	/**
	 * Service Call: ConfermaReso
	 * Parameter options:
	 * (integer) pidStore, (integer) pidReso
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function ConfermaReso($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idReso");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("ConfermaReso", $namedArgs));
	}


	/**
	 * Service Call: GetResi
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (integer) pidReso
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetResi($mixed = null) {
		$validParameters = array("integer", "integer", "integer");

		$parameterNames = array("idStore", "idUtente", "idReso");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetResi", $namedArgs));
	}


	/**
	 * Service Call: GetPuntiVenditaProd
	 * Parameter options:
	 * (integer) pidStore, (integer) pid, (integer) pTipoUtente, (string) pcodNazione, (integer) ppuntoVendita, (integer) pclickCollect, (integer) pmonoMarca
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPuntiVenditaProd($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "integer", "integer", "integer");

		$parameterNames = array("idStore", "id", "TipoUtente", "codNazione", "puntoVendita", "clickCollect", "monoMarca");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPuntiVenditaProd", $namedArgs));
	}


	/**
	 * Service Call: GetPuntiVenditaProdByProd
	 * Parameter options:
	 * (integer) pidStore, (integer) pidProd, (integer) pTipoUtente, (string) pcodNazione
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPuntiVenditaProdByProd($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idProd", "TipoUtente", "codNazione");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPuntiVenditaProdByProd", $namedArgs));
	}


	/**
	 * Service Call: InsertProvaAcquisto
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDUtente, (string) pcodice
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertProvaAcquisto($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "IDUtente", "codice");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertProvaAcquisto", $namedArgs));
	}


	/**
	 * Service Call: CheckProvaAcquisto
	 * Parameter options:
	 * (integer) pidStore, (string) pcodice
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckProvaAcquisto($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idStore", "codice");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckProvaAcquisto", $namedArgs));
	}


	/**
	 * Service Call: GetEventiByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (integer) pidCategoria, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEventiByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idUtente", "idCategoria", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEventiByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idUtente", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetSottoCategoriaByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidcategoria, (integer) pidUtente, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSottoCategoriaByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "idcategoria", "idUtente", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSottoCategoriaByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetOfferte
	 * Parameter options:
	 * (integer) pidstore, (integer) pid, (integer) pidutente, (string) pslug, (string) plingua, (integer) ppriorita, (string) pidcampagna
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetOfferte($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string", "string", "integer", "string");

		$parameterNames = array("idstore", "id", "idutente", "slug", "lingua", "priorita", "idcampagna");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetOfferte", $namedArgs));
	}


	/**
	 * Service Call: GetRigheOfferta
	 * Parameter options:
	 * (integer) pidOfferta
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetRigheOfferta($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idOfferta");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetRigheOfferta", $namedArgs));
	}


	/**
	 * Service Call: GetParametriPromozioni
	 * Parameter options:
	 * (integer) pidStore, (string) ptipoPromo, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetParametriPromozioni($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "tipoPromo", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetParametriPromozioni", $namedArgs));
	}


	/**
	 * Service Call: GetParametriPromozione
	 * Parameter options:
	 * (integer) pidStore, (integer) pidPromozione
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetParametriPromozione($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idStore", "idPromozione");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetParametriPromozione", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaByTema
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaByTema($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaByTema", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetSottocategoria
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDCategoria, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSottocategoria($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "string");

		$parameterNames = array("idStore", "IDCategoria", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSottocategoria", $namedArgs));
	}


	/**
	 * Service Call: GetGuidaTagliaByParam
	 * Parameter options:
	 * (integer) pIDprod, (string) plingua, (integer) pbyCategoria, (integer) pbyEta, (integer) pbySesso, (integer) pbyMarchio
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGuidaTagliaByParam($mixed = null) {
		$validParameters = array("integer", "string", "integer", "integer", "integer", "integer");

		$parameterNames = array("IDprod", "lingua", "byCategoria", "byEta", "bySesso", "byMarchio");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGuidaTagliaByParam", $namedArgs));
	}


	/**
	 * Service Call: IMDBOffset
	 * Parameter options:

	 * @param mixed,... See function description for parameter options
	 * @return int
	 * @throws Exception invalid function signature message
	 */
	public function IMDBOffset($mixed = null) {
		$validParameters = array("");

		$args = $this->_checkArguments(func_get_args(), $validParameters);
		return $this->SoapXmlDecode($this->__soapCall("IMDBOffset", $args));
	}


	/**
	 * Service Call: GetFidelity
	 * Parameter options:
	 * (string) pcodice
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetFidelity($mixed = null) {
		$validParameters = array("string");

		$parameterNames = array("codice");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetFidelity", $namedArgs));
	}


	/**
	 * Service Call: AssociaFidelity
	 * Parameter options:
	 * (integer) pIdfidelity, (integer) pIdutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function AssociaFidelity($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("Idfidelity", "Idutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("AssociaFidelity", $namedArgs));
	}


	/**
	 * Service Call: AttivaFidelity
	 * Parameter options:
	 * (integer) pIdutente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function AttivaFidelity($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("Idutente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("AttivaFidelity", $namedArgs));
	}


	/**
	 * Service Call: GetNotificaStockByEmail
	 * Parameter options:
	 * (string) pemail, (integer) pidstore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetNotificaStockByEmail($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("email", "idstore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetNotificaStockByEmail", $namedArgs));
	}


	/**
	 * Service Call: GetUtentiDisattivati
	 * Parameter options:
	 * (dateTime) pdata
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtentiDisattivati($mixed = null) {
		$validParameters = array("dateTime");

		$parameterNames = array("data");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtentiDisattivati", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaTree
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaTree($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaTree", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaTreeBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pslug, (integer) plivello, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaTreeBySlug($mixed = null) {
		$validParameters = array("integer", "string", "integer", "string");

		$parameterNames = array("idStore", "slug", "livello", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaTreeBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetFotoCategoriaTree
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetFotoCategoriaTree($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetFotoCategoriaTree", $namedArgs));
	}


	/**
	 * Service Call: GetCategoriaByStagione
	 * Parameter options:
	 * (integer) pidStore, (integer) pidStagione, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategoriaByStagione($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idStagione", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategoriaByStagione", $namedArgs));
	}


	/**
	 * Service Call: GetEvento
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEvento($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEvento", $namedArgs));
	}


	/**
	 * Service Call: GetEventoBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEventoBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEventoBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetEventoBySesso
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEventoBySesso($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEventoBySesso", $namedArgs));
	}


	/**
	 * Service Call: GetEventoByCategoria
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEventoByCategoria($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEventoByCategoria", $namedArgs));
	}


	/**
	 * Service Call: GetTema
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTema($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTema", $namedArgs));
	}


	/**
	 * Service Call: GetTemaBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTemaBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTemaBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetSesso
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSesso($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSesso", $namedArgs));
	}


	/**
	 * Service Call: GetSessoBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetSessoBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetSessoBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetEta
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEta($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEta", $namedArgs));
	}


	/**
	 * Service Call: GetEtaBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEtaBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEtaBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetEtaBySesso
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetEtaBySesso($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetEtaBySesso", $namedArgs));
	}


	/**
	 * Service Call: GetMarchio
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetMarchio($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetMarchio", $namedArgs));
	}


	/**
	 * Service Call: GetMarchioBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetMarchioBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetMarchioBySlug", $namedArgs));
	}


	/**
	 * Service Call: Getmarchiobyutente
	 * Parameter options:
	 * (integer) pID, (integer) pIDutente, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function Getmarchiobyutente($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("ID", "IDutente", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("Getmarchiobyutente", $namedArgs));
	}


	/**
	 * Service Call: GetMarchioBySesso
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua, (integer) pidSesso
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetMarchioBySesso($mixed = null) {
		$validParameters = array("integer", "string", "string", "integer");

		$parameterNames = array("idStore", "Slug", "lingua", "idSesso");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetMarchioBySesso", $namedArgs));
	}


	/**
	 * Service Call: GetCorr
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCorr($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCorr", $namedArgs));
	}


	/**
	 * Service Call: GetCorrBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pSlug, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCorrBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "Slug", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCorrBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetCorrBySesso
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCorrBySesso($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCorrBySesso", $namedArgs));
	}


	/**
	 * Service Call: GetCorrByRegole
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDprod, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCorrByRegole($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "IDprod", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCorrByRegole", $namedArgs));
	}


	/**
	 * Service Call: GetCorrTriggersByIdCorr
	 * Parameter options:
	 * (integer) pIDCorr
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCorrTriggersByIdCorr($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("IDCorr");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCorrTriggersByIdCorr", $namedArgs));
	}


	/**
	 * Service Call: GetCorrRegoleByIdCorr
	 * Parameter options:
	 * (integer) pIDCorr
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCorrRegoleByIdCorr($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("IDCorr");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCorrRegoleByIdCorr", $namedArgs));
	}


	/**
	 * Service Call: GetStagione
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetStagione($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "ID", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetStagione", $namedArgs));
	}


	/**
	 * Service Call: GetNazione
	 * Parameter options:
	 * (string) pcodice, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetNazione($mixed = null) {
		$validParameters = array("string", "string");

		$parameterNames = array("codice", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetNazione", $namedArgs));
	}


	/**
	 * Service Call: GetNazioneSped
	 * Parameter options:
	 * (integer) pidStore, (string) pcodice, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetNazioneSped($mixed = null) {
		$validParameters = array("integer", "string", "string");

		$parameterNames = array("idStore", "codice", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetNazioneSped", $namedArgs));
	}


	/**
	 * Service Call: GetRegione
	 * Parameter options:
	 * (string) pcodNazione, (integer) pid, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetRegione($mixed = null) {
		$validParameters = array("string", "integer", "string");

		$parameterNames = array("codNazione", "id", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetRegione", $namedArgs));
	}


	/**
	 * Service Call: GetProvincia
	 * Parameter options:
	 * (string) pcodNazione, (integer) pidRegione, (integer) pid, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProvincia($mixed = null) {
		$validParameters = array("string", "integer", "integer", "string");

		$parameterNames = array("codNazione", "idRegione", "id", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetProvincia", $namedArgs));
	}


	/**
	 * Service Call: GetComune
	 * Parameter options:
	 * (integer) pidProvincia, (integer) pid, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetComune($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idProvincia", "id", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetComune", $namedArgs));
	}


	/**
	 * Service Call: GetRecensione
	 * Parameter options:
	 * (string) pcodiceProdotto, (integer) pidRecensione
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetRecensione($mixed = null) {
		$validParameters = array("string", "integer");

		$parameterNames = array("codiceProdotto", "idRecensione");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetRecensione", $namedArgs));
	}


	/**
	 * Service Call: InsertRecensione
	 * Parameter options:
	 * (string) pcodiceProdotto, (string) pnominativo, (string) ptesto, (integer) pvoto, (dateTime) pdata, (integer) pabilitato, (string) pemail, (string) ptitolo
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertRecensione($mixed = null) {
		$validParameters = array("string", "string", "string", "integer", "dateTime", "integer", "string", "string");

		$parameterNames = array("codiceProdotto", "nominativo", "testo", "voto", "data", "abilitato", "email", "titolo");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertRecensione", $namedArgs));
	}


	/**
	 * Service Call: GetFasceSconto
	 * Parameter options:
	 * (integer) pidlistino
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetFasceSconto($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idlistino");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetFasceSconto", $namedArgs));
	}


	/**
	 * Service Call: GetCategorieSoggetti
	 * Parameter options:
	 * (integer) pidStore, (integer) pidCategoria, (string) pslugCatgoria, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCategorieSoggetti($mixed = null) {
		$validParameters = array("integer", "integer", "string", "string");

		$parameterNames = array("idStore", "idCategoria", "slugCatgoria", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCategorieSoggetti", $namedArgs));
	}


	/**
	 * Service Call: GetProdotto
	 * Parameter options:
	 * (integer) pidStore, (integer) pID, (string) plingua, (integer) pUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProdotto($mixed = null) {
		$validParameters = array("integer", "integer", "string", "integer");

		$parameterNames = array("idStore", "ID", "lingua", "Utente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetProdotto", $namedArgs));
	}


	/**
	 * Service Call: GetProdottoBySlug
	 * Parameter options:
	 * (integer) pidStore, (string) pslug, (string) plingua, (integer) pUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProdottoBySlug($mixed = null) {
		$validParameters = array("integer", "string", "string", "integer");

		$parameterNames = array("idStore", "slug", "lingua", "Utente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetProdottoBySlug", $namedArgs));
	}


	/**
	 * Service Call: CheckDisponbilita
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDProdotto, (integer) pIDValVar1, (integer) pIDValVar2, (integer) pIDValVar3, (integer) pqtaRichiesta
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function CheckDisponbilita($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("idStore", "IDProdotto", "IDValVar1", "IDValVar2", "IDValVar3", "qtaRichiesta");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("CheckDisponbilita", $namedArgs));
	}


	/**
	 * Service Call: InsertNotificaStockProd
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDprod, (integer) pvar1, (integer) pvar2, (integer) pvar3, (string) pEmail, (string) pCodiceLingua, (string) pBody, (string) purl, (double) pquantita
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertNotificaStockProd($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "string", "string", "string", "string", "double");

		$parameterNames = array("idStore", "IDprod", "var1", "var2", "var3", "Email", "CodiceLingua", "Body", "url", "quantita");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertNotificaStockProd", $namedArgs));
	}


	/**
	 * Service Call: GetPagamentiPreferenzialiByUtente
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetPagamentiPreferenzialiByUtente($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idStore", "idUtente", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetPagamentiPreferenzialiByUtente", $namedArgs));
	}


	/**
	 * Service Call: GetMinPrezzoEcommerce
	 * Parameter options:
	 * (integer) pIDProd, (integer) pIDVar1, (integer) pIDVar2, (integer) pIDVar3, (integer) pidStore, (integer) pIDListino, (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetMinPrezzoEcommerce($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("IDProd", "IDVar1", "IDVar2", "IDVar3", "idStore", "IDListino", "idUtente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetMinPrezzoEcommerce", $namedArgs));
	}


	/**
	 * Service Call: GetComposizioni
	 * Parameter options:
	 * (integer) pidCompo, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetComposizioni($mixed = null) {
		$validParameters = array("integer", "string");

		$parameterNames = array("idCompo", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetComposizioni", $namedArgs));
	}


	/**
	 * Service Call: GetGuidaTaglieExt
	 * Parameter options:
	 * (integer) pidcategoria, (integer) pidsottocat, (integer) pideta, (integer) pidsesso, (integer) pidmarchio, (integer) pidfamiglia, (integer) pidgruppo, (integer) pidsottofam, (integer) pidsottogruppo, (integer) pidtipovar, (integer) pidtema, (integer) pidevento, (integer) pidcatalbero, (integer) pidstore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGuidaTaglieExt($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("idcategoria", "idsottocat", "ideta", "idsesso", "idmarchio", "idfamiglia", "idgruppo", "idsottofam", "idsottogruppo", "idtipovar", "idtema", "idevento", "idcatalbero", "idstore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGuidaTaglieExt", $namedArgs));
	}


	/**
	 * Service Call: GetGuidaTaglieTrad
	 * Parameter options:
	 * (string) plingua, (integer) pidcategoria, (integer) pidsottocat, (integer) pideta, (integer) pidsesso, (integer) pidmarchio, (integer) pidfamiglia, (integer) pidgruppo, (integer) pidsottofam, (integer) pidsottogruppo, (integer) pidtipovar, (integer) pidtema, (integer) pidevento, (integer) pidcatalbero, (integer) pidstore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGuidaTaglieTrad($mixed = null) {
		$validParameters = array("string", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("lingua", "idcategoria", "idsottocat", "ideta", "idsesso", "idmarchio", "idfamiglia", "idgruppo", "idsottofam", "idsottogruppo", "idtipovar", "idtema", "idevento", "idcatalbero", "idstore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGuidaTaglieTrad", $namedArgs));
	}


	/**
	 * Service Call: GetGiacenzeDettagliate
	 * Parameter options:
	 * (integer) pidProd, (integer) pidvar1, (integer) pidvar2, (integer) pidvar3
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetGiacenzeDettagliate($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer");

		$parameterNames = array("idProd", "idvar1", "idvar2", "idvar3");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetGiacenzeDettagliate", $namedArgs));
	}


	/**
	 * Service Call: GetDisponbilita
	 * Parameter options:
	 * (integer) pidStore, (integer) pIDProdotto, (integer) pIDValVar1, (integer) pIDValVar2, (integer) pIDValVar3
	 * @param mixed,... See function description for parameter options
	 * @return int
	 * @throws Exception invalid function signature message
	 */
	public function GetDisponbilita($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer", "integer");

		$parameterNames = array("idStore", "IDProdotto", "IDValVar1", "IDValVar2", "IDValVar3");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetDisponbilita", $namedArgs));
	}


	/**
	 * Service Call: GetProdottiPiuAcquistati
	 * Parameter options:
	 * (integer) pUtente, (integer) prighe, (integer) ptreshold, (integer) pIdStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetProdottiPiuAcquistati($mixed = null) {
		$validParameters = array("integer", "integer", "integer", "integer");

		$parameterNames = array("Utente", "righe", "treshold", "IdStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetProdottiPiuAcquistati", $namedArgs));
	}


	/**
	 * Service Call: GetTestataByDataModifica
	 * Parameter options:
	 * (integer) pidStore, (integer) pidUtente, (dateTime) pdataDa, (dateTime) pdataA, (double) pimportoMinimo, (integer) pincEvasi, (dateTime) pdataInizioHash, (dateTime) pdataFineHash, (integer) pricercaperhash, (string) pTiporec
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetTestataByDataModifica($mixed = null) {
		$validParameters = array("integer", "integer", "dateTime", "dateTime", "double", "integer", "dateTime", "dateTime", "integer", "string");

		$parameterNames = array("idStore", "idUtente", "dataDa", "dataA", "importoMinimo", "incEvasi", "dataInizioHash", "dataFineHash", "ricercaperhash", "Tiporec");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetTestataByDataModifica", $namedArgs));
	}


	/**
	 * Service Call: GetLastDocsUtentiWeb
	 * Parameter options:
	 * (integer) pidStore, (dateTime) pdataDa, (dateTime) pdataA, (string) pTiporec, (string) pemail
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetLastDocsUtentiWeb($mixed = null) {
		$validParameters = array("integer", "dateTime", "dateTime", "string", "string");

		$parameterNames = array("idStore", "dataDa", "dataA", "Tiporec", "email");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetLastDocsUtentiWeb", $namedArgs));
	}


	/**
	 * Service Call: getRigheAppartateByIDDoc
	 * Parameter options:
	 * (integer) pidDoc
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function getRigheAppartateByIDDoc($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idDoc");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("getRigheAppartateByIDDoc", $namedArgs));
	}


	/**
	 * Service Call: setRigaAppartata
	 * Parameter options:
	 * (integer) pidDoc, (integer) priga
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function setRigaAppartata($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idDoc", "riga");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("setRigaAppartata", $namedArgs));
	}


	/**
	 * Service Call: getOCAppartati
	 * Parameter options:

	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function getOCAppartati($mixed = null) {
		$validParameters = array("");

		$args = $this->_checkArguments(func_get_args(), $validParameters);
		return $this->SoapXmlDecode($this->__soapCall("getOCAppartati", $args));
	}


	/**
	 * Service Call: GetComunicazioneBySlug
	 * Parameter options:
	 * (string) pslug, (integer) pidstore, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetComunicazioneBySlug($mixed = null) {
		$validParameters = array("string", "integer", "string");

		$parameterNames = array("slug", "idstore", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetComunicazioneBySlug", $namedArgs));
	}


	/**
	 * Service Call: GetComunicazioniByUser
	 * Parameter options:
	 * (integer) pidUser, (integer) pidstore, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetComunicazioniByUser($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idUser", "idstore", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetComunicazioniByUser", $namedArgs));
	}


	/**
	 * Service Call: GetVettore
	 * Parameter options:
	 * (integer) pID
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetVettore($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("ID");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetVettore", $namedArgs));
	}


	/**
	 * Service Call: GetNotificaStock
	 * Parameter options:

	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetNotificaStock($mixed = null) {
		$validParameters = array("");

		$args = $this->_checkArguments(func_get_args(), $validParameters);
		return $this->SoapXmlDecode($this->__soapCall("GetNotificaStock", $args));
	}


	/**
	 * Service Call: GetVarValori
	 * Parameter options:
	 * (integer) pidVariante, (integer) pidTipoVariante, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetVarValori($mixed = null) {
		$validParameters = array("integer", "integer", "string");

		$parameterNames = array("idVariante", "idTipoVariante", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetVarValori", $namedArgs));
	}


	/**
	 * Service Call: GetCataloghi
	 * Parameter options:
	 * (integer) pID, (string) pslug, (integer) pidStore, (integer) pidStagione, (integer) pidMarchio, (integer) pidCategoria, (string) plingua
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetCataloghi($mixed = null) {
		$validParameters = array("integer", "string", "integer", "integer", "integer", "integer", "string");

		$parameterNames = array("ID", "slug", "idStore", "idStagione", "idMarchio", "idCategoria", "lingua");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetCataloghi", $namedArgs));
	}


	/**
	 * Service Call: GetAgente
	 * Parameter options:
	 * (integer) pcodAgente, (integer) pIdStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetAgente($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("codAgente", "IdStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetAgente", $namedArgs));
	}


	/**
	 * Service Call: InsertUtentiStore
	 * Parameter options:
	 * (integer) pidUtente, (integer) pidStore
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function InsertUtentiStore($mixed = null) {
		$validParameters = array("integer", "integer");

		$parameterNames = array("idUtente", "idStore");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("InsertUtentiStore", $namedArgs));
	}


	/**
	 * Service Call: GetUtentiStore
	 * Parameter options:
	 * (integer) pidUtente
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function GetUtentiStore($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("idUtente");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("GetUtentiStore", $namedArgs));
	}


	/**
	 * Service Call: DeleteNotificaStock
	 * Parameter options:
	 * (integer) pIdNotifica
	 * @param mixed,... See function description for parameter options
	 * @return string
	 * @throws Exception invalid function signature message
	 */
	public function DeleteNotificaStock($mixed = null) {
		$validParameters = array("integer");

		$parameterNames = array("IdNotifica");
		$args = $this->_checkArguments(func_get_args(), $validParameters);
		$namedArgs = array();
		foreach ($parameterNames as $name) {
			$namedArgs[$name] = "~~NULL~~";
		}
		foreach ($args as $index => $value) {
			if (isset($parameterNames[$index]) && !is_null($value)) {
				$namedArgs[$parameterNames[$index]] = $value;
			}
		}
		return $this->SoapXmlDecode($this->__soapCall("DeleteNotificaStock", $namedArgs));
	}


}

?>