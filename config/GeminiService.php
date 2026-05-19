<?php
/**
 * config/GeminiService.php — AI question generation via Groq API (free).
 */

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

class GeminiService {

    private string $apiKey;
    private string $model = 'llama-3.3-70b-versatile';

    public function __construct() {
        $this->apiKey = $_ENV['GROQ_API_KEY'] ?? '';
    }

    public function generateQuestions(string $text, int $mcqCount = 5, int $tfCount = 3, bool $autoDetect = false): array|false {
        if (empty($this->apiKey)) {
            error_log('GeminiService: GROQ_API_KEY not set');
            return false;
        }

        if ($autoDetect) {
            $prompt = 'Analyze the lesson content and generate 5 to 10 quiz questions. '
                . 'If the content has existing MCQ questions extract them as type "mcq". '
                . 'If it has True/False questions extract them as type "true_false". '
                . 'Otherwise generate MCQ questions. '
                . 'OUTPUT FORMAT: Return ONLY a raw JSON array — no markdown, no code fences, no explanation, no text before or after the array. '
                . 'Each element: {"type":"mcq","question":"...","options":[{"label":"A","text":"..."},{"label":"B","text":"..."},{"label":"C","text":"..."},{"label":"D","text":"..."}],"correct_answer":"A","points":1} '
                . 'For true_false: {"type":"true_false","question":"...","options":[{"label":"T","text":"True"},{"label":"F","text":"False"}],"correct_answer":"T","points":1} '
                . "\n\nLESSON CONTENT:\n{$text}";
        } else {
            $parts = [];
            if ($mcqCount > 0) $parts[] = "{$mcqCount} MCQ";
            if ($tfCount  > 0) $parts[] = "{$tfCount} True/False";
            $typeInstruction = implode(' and ', $parts);

            $prompt = "Generate exactly {$typeInstruction} questions from the lesson content below. "
                . 'OUTPUT FORMAT: Return ONLY a raw JSON array — no markdown, no code fences, no explanation, no text before or after the array. '
                . 'MCQ element: {"type":"mcq","question":"...","options":[{"label":"A","text":"..."},{"label":"B","text":"..."},{"label":"C","text":"..."},{"label":"D","text":"..."}],"correct_answer":"A","points":1} '
                . 'True/False element: {"type":"true_false","question":"...","options":[{"label":"T","text":"True"},{"label":"F","text":"False"}],"correct_answer":"T","points":1} '
                . "\n\nLESSON CONTENT:\n{$text}";
        }

        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You are a quiz generator API. You output ONLY raw valid JSON arrays. Never use markdown. Never add explanations. Your entire response must start with [ and end with ].',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens'  => 4096,
        ]);

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Groq cURL error: $curlErr");
            return false;
        }

        if ($httpCode !== 200) {
            error_log("Groq HTTP $httpCode: " . substr($response, 0, 500));
            return false;
        }

        $data    = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            error_log('Groq: empty content');
            return false;
        }

        $content = $this->extractJsonArray($content);

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            error_log('Groq: failed to parse JSON — ' . substr($content, 0, 500));
            return false;
        }

        // Unwrap {"questions":[...]} or any single-key wrapper
        if (!isset($parsed[0])) {
            foreach ($parsed as $val) {
                if (is_array($val) && isset($val[0])) {
                    $parsed = $val;
                    break;
                }
            }
        }

        if (empty($parsed) || !isset($parsed[0])) {
            error_log('Groq: empty or non-indexed questions array after unwrap');
            return false;
        }

        return $this->normaliseQuestions($parsed);
    }

    /**
     * Extract the first JSON array from a string that may contain
     * markdown fences, leading text, or trailing text.
     */
    private function extractJsonArray(string $content): string {
        // Strip markdown code fences
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        // If it already starts with [, return as-is
        if (str_starts_with($content, '[')) {
            return $content;
        }

        // Find the first [ and last ] to extract the array
        $start = strpos($content, '[');
        $end   = strrpos($content, ']');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($content, $start, $end - $start + 1);
        }

        return $content;
    }

    /**
     * Normalise AI question objects to a consistent structure.
     */
    private function normaliseQuestions(array $parsed): array {
        $validTypes = ['mcq', 'true_false', 'identification', 'fill_blank', 'enumeration'];
        $normalised = [];

        foreach ($parsed as $q) {
            if (!is_array($q)) continue;

            $type     = in_array($q['type'] ?? '', $validTypes, true) ? $q['type'] : 'mcq';
            $question = trim($q['question'] ?? '');
            if ($question === '') continue;

            // Options
            $options = is_array($q['options'] ?? null) ? $q['options'] : [];
            if ($type === 'true_false') {
                $options = [
                    ['label' => 'T', 'text' => 'True'],
                    ['label' => 'F', 'text' => 'False'],
                ];
            }

            // Correct answer
            $ans = trim((string)($q['correct_answer'] ?? ''));
            if ($type === 'mcq') {
                $ans = strtoupper($ans);
                if (!in_array($ans, ['A','B','C','D'], true)) $ans = 'A';
            } elseif ($type === 'true_false') {
                $ans = in_array(strtoupper($ans), ['T','TRUE','1','YES'], true) ? 'T' : 'F';
            } elseif ($type === 'enumeration' && is_array($q['correct_answer'] ?? null)) {
                $ans = implode(',', array_map('trim', $q['correct_answer']));
            }

            if ($ans === '') continue;

            $normalised[] = [
                'type'           => $type,
                'question'       => $question,
                'options'        => $options,
                'correct_answer' => $ans,
                'points'         => max(1, (int)($q['points'] ?? 1)),
            ];
        }

        if (empty($normalised)) {
            error_log('Groq: no valid questions after normalisation');
            return [];
        }

        return $normalised;
    }
}

/**
 * Extract plain text from a .docx file using PHPWord.
 */
function extractDocxText(string $filePath): string {
    require_once __DIR__ . '/../vendor/autoload.php';
    try {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
        $text    = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText() . ' ';
                        }
                    }
                    $text .= "\n";
                }
            }
        }
        return trim($text);
    } catch (Exception $e) {
        error_log('extractDocxText error: ' . $e->getMessage());
        return '';
    }
}

/**
 * Extract plain text from a .pptx file by reading the XML directly.
 */
function extractPptxText(string $filePath): string {
    $text = '';
    try {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                $xml = $zip->getFromIndex($i);
                $dom = new DOMDocument();
                @$dom->loadXML($xml);
                $nodes = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't');
                foreach ($nodes as $node) {
                    $t = trim($node->textContent);
                    if ($t !== '') $text .= $t . ' ';
                }
                $text .= "\n";
            }
        }
        $zip->close();
    } catch (Exception $e) {
        error_log('extractPptxText error: ' . $e->getMessage());
    }
    return trim($text);
}

/**
 * Extract plain text from a .txt file.
 */
function extractTxtText(string $filePath): string {
    $content = file_get_contents($filePath);
    if ($content === false) return '';
    return trim(str_replace(["\r\n", "\r"], "\n", $content));
}

/**
 * Extract plain text from an .xlsx file by reading the XML directly.
 */
function extractXlsxText(string $filePath): string {
    $text = '';
    try {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return '';
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $dom = new DOMDocument();
            @$dom->loadXML($ssXml);
            foreach ($dom->getElementsByTagName('t') as $t) {
                $sharedStrings[] = $t->textContent;
            }
        }
        for ($s = 1; $s <= 20; $s++) {
            $sheetXml = $zip->getFromName("xl/worksheets/sheet{$s}.xml");
            if ($sheetXml === false) break;
            $dom = new DOMDocument();
            @$dom->loadXML($sheetXml);
            $cells = $dom->getElementsByTagName('c');
            $lastRow = null; $rowText = '';
            foreach ($cells as $cell) {
                $row = preg_replace('/[^0-9]/', '', $cell->getAttribute('r'));
                if ($lastRow !== null && $row !== $lastRow) { $text .= trim($rowText) . "\n"; $rowText = ''; }
                $lastRow = $row;
                $type  = $cell->getAttribute('t');
                $vNode = $cell->getElementsByTagName('v')->item(0);
                if (!$vNode) continue;
                $val = $vNode->textContent;
                if ($type === 's' && isset($sharedStrings[(int)$val])) $val = $sharedStrings[(int)$val];
                $rowText .= $val . "\t";
            }
            if ($rowText) $text .= trim($rowText) . "\n";
        }
        $zip->close();
    } catch (Exception $e) {
        error_log('extractXlsxText error: ' . $e->getMessage());
    }
    return trim($text);
}

/**
 * Extract plain text from a .pdf file using smalot/pdfparser.
 */
function extractPdfText(string $filePath): string {
    require_once __DIR__ . '/../vendor/autoload.php';
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($filePath);
        $text   = $pdf->getText();
        return trim(preg_replace('/\s{3,}/', "\n", $text));
    } catch (Exception $e) {
        error_log('extractPdfText error: ' . $e->getMessage());
        return '';
    }
}

/**
 * Auto-detect file type and extract text.
 */
function extractTextFromFile(string $filePath, string $ext): string {
    return match($ext) {
        'docx'  => extractDocxText($filePath),
        'pptx'  => extractPptxText($filePath),
        'xlsx'  => extractXlsxText($filePath),
        'txt'   => extractTxtText($filePath),
        'pdf'   => extractPdfText($filePath),
        default => '',
    };
}
