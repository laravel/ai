<?php

namespace Laravel\Ai\Contracts\Gateway;

interface Gateway extends AudioGateway, EmbeddingGateway, ImageGateway, TextGateway, TokenCountingGateway, TranscriptionGateway
{
    //
}
