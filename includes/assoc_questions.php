<?php
/**
 * Shared helpers for the Association question pages
 * (question_bank.php and question_form.php).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once __DIR__ . '/helpers.php';

/** Get the association's active draft submission (the working bank), or null. */
function active_draft(int $aid): ?array
{
    $st = db()->prepare("SELECT * FROM question_submissions WHERE association_id=? AND status='draft' ORDER BY id DESC LIMIT 1");
    $st->execute([$aid]);
    return $st->fetch() ?: null;
}

/** Ensure a working draft submission exists and return it. */
function ensure_draft(int $aid, array $assoc): array
{
    $draft = active_draft($aid);
    if (!$draft) {
        db()->prepare('INSERT INTO question_submissions (association_id, submission_date, contact_email, status) VALUES (?,?,?,\'draft\')')
            ->execute([$aid, date('Y-m-d'), $assoc['contact_email'] ?? '']);
        $draft = active_draft($aid);
    }
    return $draft;
}

/** Validate a question's posted fields. Returns [data, errors]. */
function read_question_input(): array
{
    $data = [
        'question_text'  => post('question_text'),
        'option_a'       => post('option_a'),
        'option_b'       => post('option_b'),
        'option_c'       => post('option_c'),
        'option_d'       => post('option_d'),
        'correct_option' => strtoupper(post('correct_option')),
        'sport'          => post('sport'),
        'category'       => post('category'),
        'difficulty'     => post('difficulty', 'Medium'),
        'reference_source' => post('reference_source'),
        'explanation'    => post('explanation'),
    ];
    $errors = [];
    if ($data['question_text'] === '') $errors[] = 'Question text is required.';
    foreach (['a', 'b', 'c', 'd'] as $o) {
        if ($data["option_{$o}"] === '') $errors[] = 'Option ' . strtoupper($o) . ' is required.';
        if (mb_strlen($data["option_{$o}"]) > 500) $errors[] = 'Option ' . strtoupper($o) . ' exceeds 500 characters.';
    }
    if (!in_array($data['correct_option'], ['A', 'B', 'C', 'D'], true)) $errors[] = 'Select exactly one correct option.';
    if (!in_array($data['difficulty'], ['Easy', 'Medium', 'Hard'], true)) $data['difficulty'] = 'Medium';
    return [$data, $errors];
}
