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

Compatibilità: PHP 7.4 – 8.x, Guzzle 6.5 oppure 7.x. La 6.5 è ammessa per i progetti che non possono
ancora passare a Guzzle 7 (es. per librerie di pagamento ferme a Guzzle 6).

Nei progetti in produzione conviene fissare la versione esatta (es. `"wearequantico/reacto-api": "v1.0.11"`)
invece di `@stable`, così un `composer update` non porta versioni nuove senza averle testate.

## Parametri e date (dalla v1.0.10)

L'API REST vuole tutti i parametri di un metodo, per nome. La libreria li invia come li inviava il servizio SOAP:

- un parametro non passato (o `null`) parte come `0` se è un numero e come `""` se è una stringa;
- una data non passata, `null`, `""` o `"~~NULL~~"` parte come `~~NULL~~` (Reacto risponde 400 alla data vuota `""`);
- le date partono nel formato `Y-m-d H:i:s`, l'unico con cui Reacto conserva l'orario (con `date("c")` tiene solo il giorno).
  Si possono passare timestamp, `DateTime`, stringhe ISO (anche `date("c")`), `Y-m-d [H:i:s]` e `d/m/Y [H:i[:s]]`.

Fino alla v1.0.9 i parametri non passati partivano tutti come `~~NULL~~` e le date in formato ISO: con alcuni metodi
Reacto restituiva meno righe (es. `GetBanner` senza `idcatAlbero`) e le scadenze perdevano l'orario.

## Timeout (dalla v1.0.11)

Ogni richiesta a Reacto ha un timeout totale di **90 secondi** (trasferimento compreso) e di 5 secondi per la connessione.
Fino alla v1.0.10 il timeout era di 30 secondi: troppo poco per i metodi lenti per natura (es. `GetPuntiVendita` con
migliaia di negozi impiega 35-50 secondi), che andavano sempre in errore e non arrivavano mai in cache. Il vecchio
client SOAP aspettava fino a 60 secondi di silenzio (`default_socket_timeout` di PHP).

90 secondi resta sotto il limite di 100 secondi dopo cui Cloudflare chiude la pagina con l'errore 524.
Se Reacto si blocca, però, ogni pagina tiene occupato un worker PHP fino a 90 secondi: un progetto che preferisce
arrendersi prima può ridurlo dal costruttore:

    $wsobj = new ReactoApiService(WSDL_LOC, array('timeout' => 30.0));

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
