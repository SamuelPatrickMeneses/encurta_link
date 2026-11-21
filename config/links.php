<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Stream de links
    |--------------------------------------------------------------------------
    |
    | O consumidor lê este número máximo de entradas por lote. O lote inteiro é
    | projetado no MySQL numa única instrução, para trocar uma ida à base de
    | dados por registo por uma ida por lote.
    |
    */

    'stream' => [
        'max_records' => (int) env('LINK_STREAM_MAX_RECORDS', 200),

        // O `schedule:work` do Laravel acorda ao minuto, por isso o intervalo
        // é expresso em minutos e limitado a 1–59. Intervalos maiores exigem
        // outra estratégia de agendamento.
        'consume_interval_minutes' => (int) env('LINK_STREAM_CONSUME_INTERVAL_MINUTES', 1),
    ],

];
