<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Booking;
use App\Models\CmsSetting;
use App\Mail\GuestFeedbackRequest;
use App\Helpers\AuditHelper;
use App\Services\GuestPrivacyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class FeedbackController extends Controller
{
    public function __construct(private GuestPrivacyService $guestPrivacy) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Get feedback form data by token (no auth needed)
    // GET /feedback/{token}
    // ─────────────────────────────────────────────────────────────────────────

    public function show(string $token)
    {
        $feedback = Feedback::with(['booking.primaryGuest', 'booking.bookingRooms.room'])
            ->where('token', $token)
            ->firstOrFail();

        if ($feedback->is_submitted) {
            return response()->json([
                'success'      => false,
                'already_done' => true,
                'message'      => 'This feedback has already been submitted.',
                'submitted_at' => $feedback->submitted_at,
            ], 200);
        }

        if ($feedback->tokenHasExpired()) {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'This feedback link has expired.',
            ], 410);
        }

        $booking = $feedback->booking;

        return response()->json([
            'success'  => true,
            'feedback' => $this->formatFeedback($feedback),
            'booking'  => [
                'reference_number' => $booking->reference_number,
                'guest_name'       => $booking->primaryGuest?->name ?? 'Guest',
                'room'             => $booking->bookingRooms->map(
                    fn ($br) => ($br->room->room_type ?? '') . ' ' . ($br->room->room_number ?? '')
                )->implode(', '),
                'check_in'         => $booking->getRawOriginal('check_in'),
                'check_out'        => $booking->getRawOriginal('check_out'),
                'stay_type'        => $booking->stay_type ?? 'overnight',
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Submit feedback by token (no auth needed)
    // POST /feedback/{token}
    // ─────────────────────────────────────────────────────────────────────────

    public function submit(Request $request, string $token)
    {
        $validated = $request->validate([
            'rating_cleanliness' => 'required|integer|min:1|max:5',
            'rating_comfort'     => 'required|integer|min:1|max:5',
            'rating_staff'       => 'required|integer|min:1|max:5',
            'rating_facilities'  => 'required|integer|min:1|max:5',
            'rating_overall'     => 'required|integer|min:1|max:5',
            'review'             => 'nullable|string|max:2000',
            'has_issue'          => 'nullable|boolean',
            'issue_type'         => 'nullable|required_if:has_issue,true|string|max:255',
            'would_recommend'    => 'nullable|boolean',
        ]);

        $result = DB::transaction(function () use ($token, $validated) {
            $feedback = Feedback::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->firstOrFail();

            if ($feedback->is_submitted) {
                return ['state' => 'already_done'];
            }

            if ($feedback->tokenHasExpired()) {
                return ['state' => 'expired'];
            }

            $feedback->loadMissing('booking.primaryGuest');
            if ($feedback->booking?->booking_status !== 'checked_out') {
                return ['state' => 'ineligible'];
            }

            $hasIssue = (bool) ($validated['has_issue'] ?? false);
            $feedback->fill([
                'rating_cleanliness' => $validated['rating_cleanliness'],
                'rating_comfort' => $validated['rating_comfort'],
                'rating_staff' => $validated['rating_staff'],
                'rating_facilities' => $validated['rating_facilities'],
                'rating_overall' => $validated['rating_overall'],
                'review' => isset($validated['review']) ? trim((string) $validated['review']) ?: null : null,
                'has_issue' => $hasIssue,
                'issue_type' => $hasIssue ? trim((string) ($validated['issue_type'] ?? '')) : null,
                'would_recommend' => $validated['would_recommend'] ?? null,
                'is_submitted' => true,
                'is_featured' => false,
                'submitted_at' => now(),
            ])->save();

            return ['state' => 'submitted', 'feedback' => $feedback->fresh(['booking.primaryGuest'])];
        });

        if ($result['state'] === 'already_done') {
            return response()->json([
                'success' => false,
                'already_done' => true,
                'message' => 'This feedback has already been submitted.',
            ], 409);
        }

        if ($result['state'] === 'expired') {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'This feedback link has expired.',
            ], 410);
        }

        if ($result['state'] === 'ineligible') {
            return response()->json([
                'success' => false,
                'message' => 'Feedback becomes available only after the booking is checked out.',
            ], 409);
        }

        $feedback = $result['feedback'];

        try {
            $this->stageFeedbackAsPendingTestimonial($feedback->fresh(['booking.primaryGuest']));
        } catch (\Throwable $e) {
            Log::error('Failed to stage submitted feedback to CMS testimonials', [
                'feedback_id' => $feedback->id,
                'booking_id' => $feedback->booking_id,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your feedback!',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: Get feedback by booking reference (for BookingDetails page)
    // GET /bookings/{reference}/feedback
    // ─────────────────────────────────────────────────────────────────────────

    public function getByBooking(Request $request, string $reference)
    {
        if (!$request->hasValidSignature()) {
            return response()->json([
                'success' => false,
                'message' => 'Feedback link is invalid or expired.',
            ], 403);
        }

        $booking = Booking::where('reference_number', $reference)->firstOrFail();
        $feedback = Feedback::where('booking_id', $booking->id)->first();

        if (!$feedback) {
            return response()->json(['success' => true, 'feedback' => null]);
        }

        return response()->json([
            'success'  => true,
            'feedback' => [
                'is_submitted'    => $feedback->is_submitted,
                'form_url'        => $feedback->is_submitted ? null : (
                    rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/feedback/' . $feedback->token
                ),
                'submitted_at'    => $feedback->submitted_at,
                'rating_overall'  => $feedback->rating_overall,
                'average_rating'  => $feedback->average_rating,
                'would_recommend' => $feedback->would_recommend,
                'review'          => $feedback->review,
                'admin_reply'     => $feedback->admin_reply,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN: List all feedbacks with filters
    // GET /admin/feedbacks
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $request->validate([
            'sort_by' => 'sometimes|in:guest,booking,room,date,cleanliness,comfort,staff,facilities,overall,recommend,issue,actions,submitted_at',
            'sort_direction' => 'sometimes|in:asc,desc',
            'audit_event' => 'sometimes|in:export_pdf',
        ]);

        $sortBy = $request->input('sort_by', 'submitted_at');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $featuredFeedbackIds = collect($this->loadCmsTestimonialsItems())
            ->filter(fn (array $item) => ($item['is_active'] ?? true) !== false)
            ->pluck('feedback_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = Feedback::with(['booking.primaryGuest', 'booking.bookingRooms.room', 'repliedBy'])
            ->where('is_submitted', true);

        if ($request->filled('rating')) {
            $query->where('rating_overall', $request->rating);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('submitted_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('submitted_at', '<=', $request->date_to);
        }

        if ($request->filled('has_issue')) {
            $query->where('has_issue', (bool) $request->has_issue);
        }

        if ($request->filled('would_recommend')) {
            $query->where('would_recommend', (bool) $request->would_recommend);
        }

        $sortableColumns = [
            'date' => 'submitted_at',
            'submitted_at' => 'submitted_at',
            'cleanliness' => 'rating_cleanliness',
            'comfort' => 'rating_comfort',
            'staff' => 'rating_staff',
            'facilities' => 'rating_facilities',
            'overall' => 'rating_overall',
            'recommend' => 'would_recommend',
            'issue' => 'has_issue',
            'actions' => 'admin_reply',
        ];
        $sortColumn = $sortableColumns[$sortBy] ?? 'submitted_at';
        $query->orderBy($sortColumn, $sortDirection);

        $feedbacks = $query->paginate($request->get('per_page', 15));

        // Summary stats
        $stats = (clone $query)->reorder()->selectRaw('
            COUNT(*) as total,
            ROUND(AVG(rating_overall), 1) as avg_overall,
            ROUND(AVG(rating_cleanliness), 1) as avg_cleanliness,
            ROUND(AVG(rating_comfort), 1) as avg_comfort,
            ROUND(AVG(rating_staff), 1) as avg_staff,
            ROUND(AVG(rating_facilities), 1) as avg_facilities,
            SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) as would_recommend_count,
            SUM(CASE WHEN has_issue = 1 THEN 1 ELSE 0 END) as has_issue_count
        ')->first();

        if ($request->input('audit_event') === 'export_pdf') {
            AuditHelper::log(
                actionActivity: 'Feedback Report Exported (PDF)',
                modulePage: 'Report Management',
                modelType: 'Report',
                modelId: null,
                recordAffected: 'Feedback Report PDF',
                oldValues: null,
                newValues: [
                    'sort_by' => $sortBy,
                    'sort_direction' => $sortDirection,
                    'rating_filter' => $request->input('rating'),
                    'date_from' => $request->input('date_from'),
                    'date_to' => $request->input('date_to'),
                    'has_issue' => $request->input('has_issue'),
                    'would_recommend' => $request->input('would_recommend'),
                ],
                action: 'exported'
            );
        }

        return response()->json([
            'success'   => true,
            'data'      => $feedbacks->through(function ($f) use ($featuredFeedbackIds) {
                $row = $this->formatFeedback($f, true);
                $row['is_featured'] = in_array((int) $f->id, $featuredFeedbackIds, true);
                return $row;
            }),
            'stats'     => $stats,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN: Reply to feedback
    // POST /admin/feedbacks/{id}/reply
    // ─────────────────────────────────────────────────────────────────────────

    public function reply(Request $request, int $id)
    {
        $request->validate(['reply' => 'required|string|max:2000']);

        $feedback = Feedback::findOrFail($id);

        $feedback->update([
            'admin_reply' => $request->reply,
            'replied_at'  => now(),
            'replied_by'  => auth()->id(),
        ]);

        AuditHelper::log(
            actionActivity: 'Feedback Reply Added',
            modulePage:     'Feedback Module',
            modelType:      'Feedback',
            modelId:        $feedback->id,
            recordAffected: 'Feedback #' . $feedback->id,
            newValues:      ['reply' => $request->reply, 'replied_by' => auth()->user()->name],
            action:         'updated'
        );

        return response()->json(['success' => true, 'message' => 'Reply saved.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STATIC: Generate feedback token for a booking after checkout
    // Called internally by BookingController after successful checkout
    // ─────────────────────────────────────────────────────────────────────────

    public static function createForBooking(Booking $booking): ?Feedback
    {
        $booking = Booking::query()->with('primaryGuest')->findOrFail($booking->id);

        if ($booking->booking_status !== 'checked_out') {
            Log::warning('Feedback request skipped because booking is not checked out.', [
                'booking_reference' => $booking->reference_number,
                'booking_id' => $booking->id,
                'booking_status' => $booking->booking_status,
            ]);

            return null;
        }

        $guestEmail = trim((string) ($booking->primaryGuest?->email ?? ''));
        if ($guestEmail === '') {
            Log::warning(
                'Feedback email skipped — no guest email address on booking: ' . $booking->reference_number,
                [
                    'booking_reference' => $booking->reference_number,
                    'booking_id' => $booking->id,
                ]
            );
            return null;
        }

        $shouldSend = false;
        $feedback = DB::transaction(function () use ($booking, &$shouldSend) {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $feedback = Feedback::query()
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->first();

            if ($feedback?->is_submitted) {
                return $feedback;
            }

            if ($feedback === null) {
                $feedback = new Feedback(['booking_id' => $booking->id]);
            }

            if (!$feedback->exists || $feedback->tokenHasExpired()) {
                $feedback->token = Feedback::generateToken();
                $feedback->token_expires_at = now()->addDays((int) config('feedback.token_lifetime_days', 30));
                $feedback->save();
                $shouldSend = true;
            }

            return $feedback;
        });

        if (!$shouldSend) {
            return $feedback;
        }

        $feedbackUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            . '/feedback/'
            . $feedback->token;

        try {
            Mail::to($guestEmail)->queue(new GuestFeedbackRequest($booking, $feedback, $feedbackUrl));
        } catch (\Throwable $e) {
            Log::error(
                'Feedback email failed after checkout — booking: '
                . $booking->reference_number
                . ' — '
                . $e->getMessage(),
                [
                    'booking_reference' => $booking->reference_number,
                    'booking_id' => $booking->id,
                    'exception_class' => $e::class,
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }

        return $feedback;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function formatFeedback(Feedback $f, bool $withBooking = false): array
    {
        $data = [
            'id'                 => $f->id,
            'booking_id'         => $f->booking_id,
            'rating_cleanliness' => $f->rating_cleanliness,
            'rating_comfort'     => $f->rating_comfort,
            'rating_staff'       => $f->rating_staff,
            'rating_facilities'  => $f->rating_facilities,
            'rating_overall'     => $f->rating_overall,
            'average_rating'     => $f->average_rating,
            'review'             => $f->review,
            'has_issue'          => $f->has_issue,
            'issue_type'         => $f->issue_type,
            'would_recommend'    => $f->would_recommend,
            'is_submitted'       => $f->is_submitted,
            'submitted_at'       => $f->submitted_at,
            'admin_reply'        => $f->admin_reply,
            'replied_at'         => $f->replied_at,
            'is_featured'        => $f->is_featured,
        ];

        if ($withBooking && $f->relationLoaded('booking')) {
            $booking = $f->booking;
            $data['guest_name']        = $booking->primaryGuest?->name ?? 'N/A';
            $data['reference_number']  = $booking->reference_number;
            $data['room']              = $booking->bookingRooms->map(
                fn ($br) => ($br->room->room_type ?? '') . ' ' . ($br->room->room_number ?? '')
            )->implode(', ');
            $data['check_in']          = $booking->getRawOriginal('check_in');
            $data['check_out']         = $booking->getRawOriginal('check_out');
            $data['replied_by_name']   = $f->repliedBy?->name;
        }

        return $data;
    }
    // ── ADMIN: Toggle featured status ─────────────────────────────────────────
    // PATCH /admin/feedbacks/{id}/feature
    public function toggleFeature(int $id): \Illuminate\Http\JsonResponse
    {
        $result = DB::transaction(function () use ($id) {
            $feedback = Feedback::query()
                ->with('booking.primaryGuest')
                ->lockForUpdate()
                ->findOrFail($id);

            if (!$feedback->is_submitted) {
                return ['state' => 'not_submitted'];
            }

            $setting = $this->lockedCmsTestimonialsSetting();
            $items = $this->decodeCmsTestimonialsItems((string) $setting->value);
            $existingIndex = collect($items)->search(function (array $item) use ($feedback) {
                return (int) ($item['feedback_id'] ?? 0) === (int) $feedback->id;
            });
            $currentlyFeatured = $existingIndex !== false
                && (($items[(int) $existingIndex]['is_active'] ?? true) !== false);

            if (!$currentlyFeatured && $feedback->rating_overall < 4) {
                return ['state' => 'low_rating'];
            }

            if (!$currentlyFeatured && trim((string) ($feedback->review ?? '')) === '') {
                return ['state' => 'missing_review'];
            }

            $next = !$currentlyFeatured;
            $entry = $this->buildGuestFeedbackTestimonialEntry($feedback, $next);

            if ($existingIndex !== false) {
                $items[(int) $existingIndex] = $entry;
            } else {
                $items[] = $entry;
            }

            $this->persistCmsTestimonialsSetting($setting, $items);

            if ($this->feedbackHasFeaturedColumn()) {
                $feedback->update(['is_featured' => $next]);
            }

            return ['state' => 'updated', 'is_featured' => $next];
        });

        if ($result['state'] === 'not_submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Only submitted feedback can be published.',
            ], 422);
        }

        if ($result['state'] === 'low_rating') {
            return response()->json([
                'success' => false,
                'message' => 'Only 4 and 5-star reviews can be featured.',
            ], 422);
        }

        if ($result['state'] === 'missing_review') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot feature a review without written feedback text.',
            ], 422);
        }

        return response()->json([
            'success'     => true,
            'is_featured' => $result['is_featured'],
            'message'     => $result['is_featured'] ? 'Review featured on site.' : 'Review removed from site.',
        ]);
    }

    // ── PUBLIC: Get featured feedbacks for homepage ────────────────────────────
    // GET /client/featured-reviews
    public function featuredReviews(): \Illuminate\Http\JsonResponse
    {
        $reviews = Feedback::with(['booking.primaryGuest'])
            ->where('is_submitted', true)
            ->where('is_featured', true)
            ->where('rating_overall', '>=', 4)
            ->whereNotNull('review')
            ->where('review', '!=', '')
            ->latest('submitted_at')
            ->get()
            ->map(fn ($f) => [
                'id'               => $f->id,
                'guest_name'       => $this->guestPrivacy->maskedName($f->booking->primaryGuest?->name),
                'rating_overall'   => $f->rating_overall,
                'review'           => $f->review,
                'would_recommend'  => $f->would_recommend,
                'submitted_at'     => $f->submitted_at,
            ]);

        return response()->json([
            'success' => true,
            'data'    => $reviews,
        ]);
    }
    
    public function publicReviews(): \Illuminate\Http\JsonResponse
    {
        $reviews = Feedback::with(['booking.primaryGuest', 'booking.bookingRooms.room'])
            ->where('is_submitted', true)
            ->where('is_featured', true)
            ->where('rating_overall', '>=', 4)
            ->whereNotNull('review')
            ->where('review', '!=', '')
            ->latest('submitted_at')
            ->get()
            ->map(fn ($f) => [
                'id'             => $f->id,
                'guest_name'     => $this->guestPrivacy->maskedName($f->booking->primaryGuest?->name),
                'review'         => $f->review,
                'rating_overall' => $f->rating_overall,
                'room'           => $f->booking->bookingRooms->map(
                    fn ($br) => ($br->room->room_type ?? '') . ' ' . ($br->room->room_number ?? '')
                )->implode(', '),
                'submitted_at'   => $f->submitted_at,
            ]);
    
        return response()->json([
            'success' => true,
            'data'    => $reviews,
        ]);
    }

    private function loadCmsTestimonialsItems(): array
    {
        $raw = (string) CmsSetting::query()
            ->where('key', 'testimonials_items')
            ->value('value');

        return $this->decodeCmsTestimonialsItems($raw);
    }

    private function buildGuestFeedbackTestimonialEntry(Feedback $feedback, bool $isActive = true): array
    {
        $submittedAt = $feedback->submitted_at;

        return [
            'id' => 'feedback-' . (int) $feedback->id,
            'guest_name' => $this->guestPrivacy->maskedName($feedback->booking?->primaryGuest?->name),
            'review_text' => (string) $feedback->review,
            'star_rating' => max(1, min(5, (int) ($feedback->rating_overall ?? 1))),
            'date' => $submittedAt ? $submittedAt->toDateString() : now()->toDateString(),
            'is_active' => $isActive,
            'source' => 'guest_feedback',
            'feedback_id' => (int) $feedback->id,
        ];
    }

    private function feedbackHasFeaturedColumn(): bool
    {
        return Schema::hasColumn('feedbacks', 'is_featured');
    }

    private function stageFeedbackAsPendingTestimonial(Feedback $feedback): void
    {
        $reviewText = trim((string) ($feedback->review ?? ''));
        $overallRating = (int) ($feedback->rating_overall ?? 0);
        if ($overallRating < 4 || $reviewText === '') {
            return;
        }

        $feedback->loadMissing('booking.primaryGuest');

        DB::transaction(function () use ($feedback) {
            $setting = $this->lockedCmsTestimonialsSetting();
            $items = $this->decodeCmsTestimonialsItems((string) $setting->value);
            $existingIndex = collect($items)->search(function (array $item) use ($feedback) {
                return (int) ($item['feedback_id'] ?? 0) === (int) $feedback->id;
            });

            if ($existingIndex !== false) {
                return;
            }

            $items[] = $this->buildGuestFeedbackTestimonialEntry($feedback, false);
            $this->persistCmsTestimonialsSetting($setting, $items);
        });
    }

    private function lockedCmsTestimonialsSetting(): CmsSetting
    {
        CmsSetting::query()->firstOrCreate(
            ['key' => 'testimonials_items'],
            [
                'value' => '[]',
                'type' => 'json',
                'group' => 'testimonials',
                'label' => 'Testimonials Items',
            ]
        );

        return CmsSetting::query()
            ->where('key', 'testimonials_items')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function decodeCmsTestimonialsItems(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, fn ($row) => is_array($row)))
            : [];
    }

    private function persistCmsTestimonialsSetting(CmsSetting $setting, array $items): void
    {
        $setting->forceFill([
            'value' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'type' => 'json',
            'group' => 'testimonials',
            'label' => 'Testimonials Items',
        ])->save();
    }
}
