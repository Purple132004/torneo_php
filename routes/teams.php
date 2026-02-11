<?php

use App\Models\Team;
use App\Utils\Request;
use App\Utils\Response;
use App\Database\DB;
use Pecee\SimpleRouter\SimpleRouter as Router;

/**
 * GET /api/teams - Lista squadre
 */
Router::get('/teams', function() {
    try {
        $teams = Team::all();
        Response::success($teams)->send();
    } catch (\Exception $e) {
        Response::error('Errore nel recupero delle squadre: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * GET /api/teams/{id}
 */
Router::get('/teams/{id}', function($id) {
    try {
        $team = Team::find((int)$id);
        if ($team === null) {
            Response::error('Team non trovato', Response::HTTP_NOT_FOUND)->send();
        }
        Response::success($team)->send();
    } catch (\Exception $e) {
        Response::error('Errore: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * POST /api/teams
 */
Router::post('/teams', function() {
    try {
        $request = new Request();
        $data = $request->json();

        $errors = Team::validate($data);
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $team = Team::create($data);
        Response::success($team, Response::HTTP_CREATED, 'Team creato con successo')->send();
    } catch (\Exception $e) {
        Response::error('Errore durante la creazione del team: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * PUT/PATCH /api/teams/{id}
 */
Router::match(['put','patch'], '/teams/{id}', function($id) {
    try {
        $request = new Request();
        $data = $request->json();

        $team = Team::find((int)$id);
        if ($team === null) Response::error('Team non trovato', Response::HTTP_NOT_FOUND)->send();

        $errors = Team::validate(array_merge($team->toArray(), $data));
        if (!empty($errors)) {
            Response::error('Errore di validazione', Response::HTTP_BAD_REQUEST, $errors)->send();
            return;
        }

        $team->update($data);
        Response::success($team, Response::HTTP_OK, 'Team aggiornato con successo')->send();
    } catch (\Exception $e) {
        Response::error('Errore durante l\'aggiornamento del team: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * DELETE /api/teams/{id}
 * Soft-delete se ha partecipato a tornei, altrimenti elimina fisicamente
 */
Router::delete('/teams/{id}', function($id) {
    try {
        $team = Team::find((int)$id);
        if ($team === null) Response::error('Team non trovato', Response::HTTP_NOT_FOUND)->send();

        // verifica partecipazioni
        $participations = DB::select('SELECT * FROM tournament_participants WHERE team_id = :tid', ['tid' => $team->id]);
        $playedMatches = DB::select('SELECT * FROM matches WHERE home_team_id = :tid OR away_team_id = :tid', ['tid' => $team->id]);

        if (!empty($participations) || !empty($playedMatches)) {
            // soft-delete: aggiorna deleted_at
            DB::update('UPDATE teams SET deleted_at = now(), updated_at = now() WHERE id = :id', ['id' => $team->id]);
            $team = Team::find($team->id); // ricarica
            Response::success($team, Response::HTTP_OK, 'Team marcato come eliminato (soft-delete)')->send();
            return;
        }

        // nessuna partecipazione -> elimina fisicamente
        $team->delete();
        Response::success(null, Response::HTTP_OK, 'Team eliminato') -> send();
    } catch (\Exception $e) {
        Response::error('Errore durante l\'eliminazione del team: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});
