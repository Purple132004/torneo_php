# Simple Rest API

Backend PHP per la gestione di tornei di calcio con bracket a eliminazione diretta. Gestisce creazione tornei, aggiunta/rimozione partecipanti, generazione automatica di match, registrazione risultati, simulazione di partite e calcolo del vincitore.

## Librerie principali
- PHP 8.1+ con simple-router (`pecee/simple-router`) per il routing dichiarativo e REST API.
- PostgreSQL per persistenza dati.
- ORM `BaseModel` con metodi CRUD (`all`, `find`, `create`, `update`, `delete`)

## Struttura del Progetto

```
nome-progetto/
├── config/
│   ├── database.php     # Configurazione database
│   └── cors.php         # Configurazione CORS
├── routes/
│   └── index.php        # Definizione route
├── public/
│   └── index.php        # Entry point
├── src/
│   ├── bootstrap.php    
│   ├── Database/
│   ├── ├── DB.php              #  DB
│   │   └── JSONDB.php          #  JSONDB
│   ├── Models/
│   │   └── BaseModel.php       #  BaseModel
│   └── Utils/
│       ├── Request.php         
│       └── Response.php        # Gestione risposte JSON
├── composer.json        # Dipendenze Composer
└── README.md           # Questo file
```

## Funzionamento del backend

### Routing e gestione delle route
- Il routing è dichiarativo via `pecee/simple-router`
- I metodi HTTP (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`) sono mappati tramite `Router::get()`, `Router::post()`, etc., 

### Model Layer e validazione centralizzata
- I Model estendono `BaseModel` e dichiarano le regole di validazione tramite il trait `WithValidate`. Le regole sono definite in `validationRules()`
- Ogni operazione CRUD (`create`, `update`) chiama automaticamente `validate()` prima di eseguire la modifica, garantendo coerenza dei dati senza logica sparsa nelle route.


### Gestione delle richieste e risposte
- La classe `Response` gestisce tutte le risposte con `success()` e `error()`, includendo una risposta uniforme (`data`, `message`, `errors`) e stati corretti (200, 201, 400, 404, 500).

### Logica di business complessa
- I tornei seguono logiche di bracket autogenerati: quando un torneo è creato, il backend calcola il numero di round tramite i partecipanti, crea i round record e genera gli accoppiamenti del primo turno in modo casuale.
- Quando un match riceve il risultato (goal home/away), il backend determina il vincitore e crea automaticamente il match del turno successivo.
- Endpoint admin come `/tournaments/{id}/participants` rigenerano l'intero bracket a partire dai partecipanti correnti, verificando sempre il numero.


## Installazione

```bash
# Installa dipendenze
composer install

# Aggiorna autoload dopo aggiunta classi
composer dump-autoload

# Avvia server di sviluppo (PHP built-in)
php -S localhost:8080 -t public
```