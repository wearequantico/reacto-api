# Reacto API Service

Libreria in sostituzione delle vecchie API Soap di Reacto 


## Installazione

In `composer.json` inserire

    "wearequantico/reacto-api" : "@stable"

Attenzione, verificare che la versione di php sia almeno 7.4

    "config": {
	    "platform": {  
		    "php":  "7.4" 
	    } 
    }

Procedere con `composer install` o `composer update`

## Utilizzo

- In `lib/config_general.php` , sostituire l'indirizzo del webservice, in 
in genere l'url ha un percorso come:
http://localhost:28080/ReactoWa_CLIENTE/APIClass
    
 - in lib/config_general.php , 

	 sostituire
	 
	 

	    $wsobj = new WebcommerceEcom2Service();
	con

	     use Wearequantico\ReactoApi\ReactoApiService;
	     $wsobj  =  new  ReactoApiService();

	il costruttore ReactoApiService ha due parametri opzionali:
	$url = url dei WS (di default usa la costante WSDL_LOC)
	$config = Array di configurazioni del client Guzzle ( https://docs.guzzlephp.org/en/latest/request-options.html )
- In classes/WebcommerceEcomServiceWrapper.php , rimuovere nel costruttore il tipo:


	 Da

	    function  __construct(WebcommerceEcom2Service  $wsobj  ,  Cache  $cacheobj  =  null) {
	   

	 A

	    function  __construct(  $wsobj  ,  Cache  $cacheobj  =  null) {



## Gestione Errori

In caso di errori, viene mostrata una pagina error 500 con un codice di errore, inserire il codice di errore nella url
in Query String.

Es: `?view_error=CODICE` per visualizzare le informazioni sull'errore
**Importante:** Il codice di errore vive in sessione, non è utilizzabile da altri 
