<?php
/**
 * config/GeminiService.php — AI question generation via Groq API (free).
 */

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

class GeminiService {

    private string $apiKey;
    private string $model = 'llama-3.1-8b-instant';

    public function __construct() {
        $this->apiKey = $_ENV['GROQ_API_KEY'] ?? '';
    }

    public function generateQuestions(string $text, int $mcqCount = 5, int $tfCount = 3, bool $autoDetect = false): array|false {
        if (empty($this->apiKey)) {
            error_log('GeminiService: GROQ_API_KEY not set');
            return false;
        }

        if ($autoDetect) {
            // Let the AI decide the best question types and counts based on content
            $prompt = "You are an expert quiz generator. Analyze the lesson content below carefully.\n\n"
                . "IMPORTANT RULES:\n"
                . "1. If the content already contains multiple-choice questions, extract and reuse them as MCQ type.\n"
                . "2. If the content contains True/False questions, extract them as true_false type.\n"
                . "3. If the content is plain lesson material, generate the most appropriate mix of question types based on the content.\n"
                . "4. Generate between 5 and 15 questions total. Choose the best types for the content.\n"
                . "5. Return ONLY a valid JSON array. No markdown, no code blocks, no explanation.\n\n"
                . "Each object must have:\n"
                . "- \"type\": \"mcq\" or \"true_false\"\n"
                . "- \"question\": the question text\n"
                . "- \"options\": array of {\"label\": \"A\", \"text\": \"...\"} — A/B/C/D for MCQ, [{\"label\":\"T\",\"text\":\"True\"},{\"label\":\"F\",\"text\":\"False\"}] for true_false\n"
                . "- \"correct_answer\": the correct label (A/B/C/D for MCQ, T or F for true_false)\n"
                . "- \"points\": 1\n\n"
                . "LESSON CONTENT:\n{$text}";
        } else {
            // Teacher-specified counts
            $parts = [];
            if ($mcqCount > 0) $parts[] = "{$mcqCount} multiple-choice questions (MCQ)";
            if ($tfCount  > 0) $parts[] = "{$tfCount} True/False questions";
            $typeInstruction = implode(' and ', $parts);

            $prompt = "You are a quiz generator. Based on the lesson content below, generate exactly {$typeInstruction}.\n\n"
                . "Return ONLY a valid JSON array. No markdown, no code blocks, no explanation. Each object must have:\n"
                . "- \"type\": \"mcq\" or \"true_false\"\n"
                . "- \"question\": the question text\n"
                . "- \"options\": array of {\"label\": \"A\", \"text\": \"...\"} — A/B/C/D for MCQ, [{\"label\":\"T\",\"text\":\"True\"},{\"label\":\"F\",\"text\":\"False\"}] for true_false\n"
                . "- \"correct_answer\": the correct label\n"
                . "- \"points\": 1\n\n"
                . "LESSON CONTENT:\n{$text}";
        }

        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => 'You are a quiz generator. Always respond with a valid JSON array only. No markdown, no explanation.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0.4,
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
            CURLOPT_SSL_VERIFYPEER => true,   // Always verify SSL certificates
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

        // Strip markdown code fences if model adds them
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            error_log('Groq: failed to parse JSON — ' . substr($content, 0, 300));
            return false;
        }

        // Unwrap if model returned {"questions": [...]}
        if (!isset($parsed[0]) && is_array(array_values($parsed)[0] ?? null)) {
            $parsed = array_values($parsed)[0];
        }

        if (empty($parsed)) {
            error_log('Groq: empty questions array');
            return false;
        }

        return $parsed;
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
