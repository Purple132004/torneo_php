<?php

use App\Models\TournamentMatch;
use App\Utils\Request;
use App\Utils\Response;
use Pecee\SimpleRouter\SimpleRouter as Router;

/**
 * POST /api/matches/{id}/result
 * body: { home_goals: int, away_goals: int }
 */
Router::post('/matches/{id}/result', function($id) {
    try {
        $request = new Request();
        $data = $request->json();

        $homeGoals = isset($data['home_goals']) ? (int)$data['home_goals'] : null;
        $awayGoals = isset($data['away_goals']) ? (int)$data['away_goals'] : null;

        $match = TournamentMatch::find((int)$id);
        if ($match === null) {
            Response::error('Match non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        // Verifica che la partita abbia due squadre assegnate
        if ($match->home_team_id === null || $match->away_team_id === null) {
            Response::error('Impossibile registrare risultato: una delle squadre non è assegnata', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        // Validazione minima
        if ($homeGoals === null || $awayGoals === null) {
            Response::error('home_goals e away_goals sono obbligatori', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        if ($homeGoals === $awayGoals) {
            Response::error('Pareggio non consentito: inviare un vincitore definitivo', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $match->setResult($homeGoals, $awayGoals);

        Response::success($match)->send();
    } catch (\Exception $e) {
        Response::error('Errore durante il salvataggio del risultato: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});

/**
 * PATCH /api/matches/{id} - Aggiorna home_team_id / away_team_id prima del risultato
 * body: { home_team_id: int|null, away_team_id: int|null }
 */
Router::match(['patch','put'], '/matches/{id}', function($id) {
    try {
        $request = new Request();
        $data = $request->json();

        $match = TournamentMatch::find((int)$id);
        if ($match === null) {
            Response::error('Match non trovato', Response::HTTP_NOT_FOUND)->send();
            return;
        }

        // Non permettere modifiche se risultato già registrato
        if ($match->winner_team_id !== null) {
            Response::error('Impossibile modificare squadre: risultato già registrato', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $home = array_key_exists('home_team_id', $data) ? ($data['home_team_id'] !== null ? (int)$data['home_team_id'] : null) : $match->home_team_id;
        $away = array_key_exists('away_team_id', $data) ? ($data['away_team_id'] !== null ? (int)$data['away_team_id'] : null) : $match->away_team_id;

        if ($home !== null && $away !== null && $home === $away) {
            Response::error('home_team_id e away_team_id non possono essere uguali', Response::HTTP_BAD_REQUEST)->send();
            return;
        }

        $match->update(['home_team_id' => $home, 'away_team_id' => $away]);
        Response::success($match)->send();
    } catch (\Exception $e) {
        Response::error('Errore durante l\'aggiornamento del match: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR)->send();
    }
});
