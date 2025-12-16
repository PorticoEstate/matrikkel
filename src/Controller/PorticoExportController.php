<?php
/**
 * PorticoExportController - REST API for Portico hierarchy export
 * 
 * Endpoint: GET /api/portico/export
 * 
 * Query parameters:
 * - kommune=XXXX (optional): filter by kommunenummer
 * - organisasjonsnummer=XXXXXX (optional): filter by owner
 * 
 * Response format: JSON with nested hierarchy
 * 
 * @author Sigurd Nes
 * @date 2025-10-28
 */

namespace Iaasen\Matrikkel\Controller;

use Iaasen\Matrikkel\Service\PorticoExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

#[Route('/api/portico', name: 'api_portico_')]
class PorticoExportController extends AbstractController
{
    public function __construct(
        private PorticoExportService $exportService,
    ) {}

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): JsonResponse
    {
        try {
            // Parse query parameters
            $kommune = $request->query->get('kommune');
            $organisasjonsnummer = $request->query->get('organisasjonsnummer');

            // Validate kommune if provided
            if ($kommune && (!ctype_digit($kommune) || strlen($kommune) !== 4)) {
                return $this->jsonError('Kommune must be a 4-digit number', 400);
            }

            // Export hierarchy
            $data = $this->exportService->export(
                $kommune ? (int)$kommune : null,
                $organisasjonsnummer
            );

            // Success response
            return new JsonResponse([
                'data' => $data,
                'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
                'status' => 'success',
            ]);

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    #[Route('/export/spreadsheet', name: 'export_spreadsheet', methods: ['GET'])]
    public function exportSpreadsheet(Request $request): StreamedResponse
    {
        try {
            // Parse query parameters
            $kommune = $request->query->get('kommune');
            $organisasjonsnummer = $request->query->get('organisasjonsnummer');

            // Validate kommune if provided
            if ($kommune && (!ctype_digit($kommune) || strlen($kommune) !== 4)) {
                return new StreamedResponse(function () {
                    echo json_encode(['error' => 'Kommune must be a 4-digit number']);
                }, 400);
            }

            // Export as spreadsheet
            $spreadsheet = $this->exportService->exportAsSpreadsheet(
                $kommune ? (int)$kommune : null,
                $organisasjonsnummer
            );

            // Create filename
            $timestamp = (new \DateTime())->format('Y-m-d_His');
            $filename = "portico_export_{$timestamp}.xlsx";

            // Stream response
            return new StreamedResponse(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            }, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);

        } catch (\Exception $e) {
            return new StreamedResponse(function () use ($e) {
                echo json_encode(['error' => $e->getMessage()]);
            }, 500);
        }
    }

    /**
     * Helper: return error JSON response
     */
    private function jsonError(string $message, int $statusCode = 400): JsonResponse
    {
        return new JsonResponse([
            'error' => $message,
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM),
            'status' => 'error',
        ], $statusCode);
    }
}
