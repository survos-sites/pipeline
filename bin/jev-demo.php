<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\TypeSafe\Evaluation;
use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\ChoiceQuestion;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\NoulQuestion;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\ScoreQuestion;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Run independently of the application kernel and its database services.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$apiKey = $_SERVER['TYPESAFE_API_KEY'] ?? $_ENV['TYPESAFE_API_KEY'] ?? getenv('TYPESAFE_API_KEY');
if (!is_string($apiKey) || '' === trim($apiKey)) {
    fwrite(STDERR, "Set TYPESAFE_API_KEY in .env.local or your environment, then run php bin/jev-demo.php.\n");
    exit(1);
}

// Optional CLI text is sent to TypeSafe; the default is a fictional museum record.
$state = $argv[1] ?? 'A black-and-white photograph of a railway station, dated 1924. The photographer is unknown.';
$result = Factory::createPlatform($apiKey)->invoke('jev-latest', new Evaluation($state, [
    'kind' => new ChoiceQuestion('What kind of collection item is described?', [
        'photograph' => 'A photographic image',
        'document' => 'A written or printed document',
        'object' => 'A physical artifact other than a photograph or document',
    ]),
    'has_date' => new NoulQuestion('Does the description explicitly include a date or year?'),
    'detail' => new ScoreQuestion('How detailed is this description?', ['Minimal', 'Moderate', 'Detailed']),
]));

$answers = $result->asObject();
echo json_encode([
    'kind' => $answers->getChoice('kind')->getChoice(),
    'kind_confidence' => $answers->getChoice('kind')->getConfidence(),
    'kind_probabilities' => $answers->getChoice('kind')->getProbabilities(),
    'has_date' => $answers->getNoul('has_date')->isTrue(),
    'date_probability' => $answers->getNoul('has_date')->getProbability(),
    'detail_score' => $answers->getScore('detail')->getScore(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
