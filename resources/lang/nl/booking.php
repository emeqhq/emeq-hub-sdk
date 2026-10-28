<?php

declare(strict_types=1);

/*
 * Copy voor de uitkomsten die dit package zélf bepaalt — de gevallen waarin Hub
 * geen antwoord gaf om te tonen. Publiceer `hub-translations` om ze te
 * herschrijven.
 *
 * Wat Hub wél beantwoordde, toont de SDK met Hubs eigen bericht. Dat noemt de
 * relatie of de grootboekcode die ontbreekt, en zegt wat je eraan doet — een
 * regel hier kan dat niet. Eén bron dus, en die staat in Hub: een nieuwe
 * foutcode heeft daar meteen de goede tekst, zonder SDK-release en zonder dat
 * één consumer iets hoeft te updaten.
 */

return [

    'not_found' => 'Dit document bestaat niet meer.',
    'not_allowed' => 'Je mag dit document niet bewerken, dus ook niet boeken.',
    'temporarily_unavailable' => 'De boekhouding is even niet bereikbaar. Er is niets geboekt; probeer het zo nog eens.',
    'already_in_progress' => 'Er loopt al een boeking van dit document. Wacht tot die klaar is.',
    'rate_limited' => 'De boekhouding neemt even geen boekingen aan. Er is niets geboekt; probeer het later nog eens.',

    'error' => [
        // Laatste redmiddel voor een rij zonder bericht — een rij die dit
        // package niet zelf schreef, want die draagt er altijd één.
        'unknown' => 'De boekhouding gaf een onbekende foutmelding.',
    ],

];
