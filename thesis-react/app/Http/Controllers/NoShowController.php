<?php

namespace App\Http\Controllers;

use App\Exceptions\NoShowTransitionException;
use App\Mail\NoShowNotification;
use App\Models\Booking;
use App\Services\NoShowService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NoShowController extends Controller
{
    public function __construct(private NoShowService $noShowService) {}

    public function markAsNoShow(Request $request, int|string $bookingId)
    {
        $validated = $request->validate([
            'contacted_guest' => ['required', 'accepted'],
            'contact_method' => ['required', 'in:phone,email,sms,other'],
            'contacted_at' => ['required', 'date'],
            'contact_outcome' => ['required', 'in:no_response,number_unreachable,message_left,other'],
            'contact_notes' => ['nullable', 'string', 'max:500'],
        ], [
            'contacted_guest.accepted' => 'Please confirm that you attempted to contact the guest.',
        ]);

        try {
            $result = $this->noShowService->mark($bookingId, $validated, $request->user());
            /** @var Booking $booking */
            $booking = $result['booking'];
            $alreadyProcessed = (bool) $result['already_processed'];

            if ($alreadyProcessed) {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking was already marked as No-Show.',
                    'already_processed' => true,
                    'notification_status' => $booking->no_show_email_status,
                    'data' => $booking,
                ]);
            }

            $guestEmail = trim((string) $booking->primaryGuest?->email);
            if ($guestEmail === '') {
                return response()->json([
                    'success' => true,
                    'message' => 'Booking marked as No-Show. No guest email is available; please notify the guest manually.',
                    'already_processed' => false,
                    'notification_status' => 'not_applicable',
                    'data' => $booking,
                ]);
            }

            try {
                Mail::to($guestEmail)->queue(new NoShowNotification($booking));
                $booking->refresh();

                return response()->json([
                    'success' => true,
                    'message' => 'Booking marked as No-Show. Guest notification was queued.',
                    'already_processed' => false,
                    'notification_status' => $booking->no_show_email_status,
                    'data' => $booking,
                ]);
            } catch (\Throwable $mailError) {
                Booking::query()->whereKey($booking->id)->update([
                    'no_show_email_status' => 'failed',
                    'no_show_email_failed_at' => now(),
                    'no_show_email_last_error' => Str::limit($mailError->getMessage(), 5000, ''),
                ]);

                Log::warning('Failed to queue no-show email', [
                    'booking_id' => $booking->id,
                    'reference' => $booking->reference_number,
                    'error' => $mailError->getMessage(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Booking marked as No-Show, but the email could not be queued. Please notify the guest manually.',
                    'already_processed' => false,
                    'notification_status' => 'failed',
                    'data' => $booking->fresh(),
                ]);
            }
        } catch (NoShowTransitionException $e) {
            return response()->json(array_merge([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->context), $e->httpStatus);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Failed to mark booking as no-show', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark booking as no-show.',
            ], 500);
        }
    }
}
