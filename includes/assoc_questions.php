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
    if (!in_array($data['difficulty'], ['Easy', 'Medium', 'Hard'], true)) $errors[] = 'Difficulty is required.';
    if ($data['sport'] === '') $errors[] = 'Sport is required.';
    return [$data, $errors];
}

/** Expected column order for bulk question upload (CSV/XLSX header row). */
function question_upload_columns(): array
{
    return ['question_text', 'option_a', 'option_b', 'option_c', 'option_d',
        'correct_option', 'sport', 'category', 'difficulty', 'reference_source', 'explanation'];
}

/**
 * Validate one bulk-upload row (positional, matching question_upload_columns()).
 * Returns [data, errors]. Difficulty defaults to Medium when blank.
 */
function read_question_row(array $row): array
{
    $get = fn(int $i) => trim((string) ($row[$i] ?? ''));
    $data = [
        'question_text'    => $get(0),
        'option_a'         => $get(1),
        'option_b'         => $get(2),
        'option_c'         => $get(3),
        'option_d'         => $get(4),
        'correct_option'   => strtoupper($get(5)),
        'sport'            => $get(6),
        'category'         => $get(7),
        'difficulty'       => ucfirst(strtolower($get(8))) ?: 'Medium',
        'reference_source' => $get(9),
        'explanation'      => $get(10),
    ];
    $errors = [];
    if ($data['question_text'] === '') $errors[] = 'question_text is required';
    foreach (['a', 'b', 'c', 'd'] as $o) {
        if ($data["option_{$o}"] === '') $errors[] = 'option_' . $o . ' is required';
        elseif (mb_strlen($data["option_{$o}"]) > 500) $errors[] = 'option_' . $o . ' exceeds 500 chars';
    }
    if (!in_array($data['correct_option'], ['A', 'B', 'C', 'D'], true)) $errors[] = 'correct_option must be A/B/C/D';
    if ($data['sport'] === '') $errors[] = 'sport is required';
    if (!in_array($data['difficulty'], ['Easy', 'Medium', 'Hard'], true)) $errors[] = 'difficulty must be Easy/Medium/Hard';
    return [$data, $errors];
}
