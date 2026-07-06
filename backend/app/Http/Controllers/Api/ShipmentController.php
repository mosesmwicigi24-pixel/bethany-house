<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\ShipmentTracking;
use App\Models\ShipmentAttachment;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Support\SortResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 3 - Shipment & Delivery Tracking
 *
 * Tracking statuses (ordered pipeline):
 *   order_confirmed → processing → ready_to_ship → picked_up
 *   → in_transit → out_for_delivery → delivery_attempted
 *   → delivered | exception | cancelled
 */
class ShipmentController extends Controller
{
    const MAX_ATTACHMENT_KB    = 10240; // 10 MB
    const ALLOWED_ATTACHMENT_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
    ];

    // Status labels and their display order for the milestone progress bar
    const MILESTONE_STATUSES = [
        'order_confirmed',
        'processing',
        'ready_to_ship',
        'picked_up',
        'in_transit',
        'out_for_delivery',
        'delivery_attempted',
        'delivered',
    ];

    const STATUS_LABELS = [
        'order_confirmed'    => 'Order Confirmed',
        'processing'         => 'Processing',
        'ready_to_ship'      => 'Ready to Ship',
        'picked_up'          => 'Picked Up',
        'in_transit'         => 'In Transit',
        'out_for_delivery'   => 'Out for Delivery',
        'delivery_attempted' => 'Delivery Attempted',
        'delivered'          => 'Delivered',
        'exception'          => 'Exception',
        'cancelled'          => 'Cancelled',
    ];

    // =========================================================================
    // GET /admin/shipments  - admin list
    // =========================================================================

    public function index(Request $request)
    {
        $query = DB::table('order_shipments as s')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->leftJoin('users as u', 'o.user_id', '=', 'u.id')
            ->select(
                's.id',
                's.order_id',
                's.shipment_number',
                's.carrier',
                's.tracking_number',
                's.tracking_token',
                's.carrier_tracking_url',
                's.status',
                's.shipped_at',
                's.delivered_at',
                's.estimated_delivery_date',
                's.notes',
                's.created_at',
                's.updated_at',
                'o.order_number',
                'o.customer_first_name',
                'o.customer_last_name',
                'o.customer_email'
            );

        if ($request->filled('status'))     $query->where('s.status', $request->status);
        if ($request->filled('carrier'))    $query->where('s.carrier', $request->carrier);
        if ($request->filled('order_id'))   $query->where('s.order_id', (int) $request->order_id);
        if ($request->filled('start_date')) $query->whereDate('s.shipped_at', '>=', $request->start_date);
        if ($request->filled('end_date'))   $query->whereDate('s.shipped_at', '<=', $request->end_date);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('s.tracking_number', 'ILIKE', "%{$search}%")
                  ->orWhere('o.order_number', 'ILIKE', "%{$search}%")
                  ->orWhere('o.customer_first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('o.customer_last_name',  'ILIKE', "%{$search}%");
            });
        }

        [$sortBy, $sortOrder] = SortResolver::resolve(
            $request->get('sort_by'),
            $request->get('sort_order', 'desc'),
            ['shipped_at', 'status', 'carrier', 'estimated_delivery_date', 'created_at', 'updated_at', 'tracking_number'],
            'shipped_at'
        );
        $query->orderBy("s.{$sortBy}", $sortOrder);

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    // =========================================================================
    // GET /admin/shipments/{id}  - admin detail with full tracking history
    // =========================================================================

    public function show($id)
    {
        $shipment = DB::table('order_shipments as s')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->leftJoin('users as u', 'o.user_id', '=', 'u.id')
            ->where('s.id', $id)
            ->select(
                's.id',
                's.order_id',
                's.shipment_number',
                's.carrier',
                's.tracking_number',
                's.tracking_token',
                's.carrier_tracking_url',
                's.status',
                's.shipped_at',
                's.delivered_at',
                's.estimated_delivery_date',
                's.notes',
                's.created_at',
                's.updated_at',
                'o.order_number',
                'o.customer_first_name',
                'o.customer_last_name',
                'o.customer_email',
                'o.customer_phone',
                'o.shipping_address_line1',
                'o.shipping_city',
                'o.shipping_country_code'
            )
            ->first();

        if (!$shipment) {
            return response()->json(['message' => 'Shipment not found'], 404);
        }

        // All events - admin sees everything
        $tracking = DB::table('shipment_tracking as t')
            ->leftJoin('users as u', 't.added_by', '=', 'u.id')
            ->where('t.shipment_id', $id)
            ->orderBy('t.event_time', 'asc')
            ->select('t.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as added_by_name"))
            ->get();

        // Build the public tracking URL
        $trackingUrl = $shipment->tracking_token
            ? $this->frontendUrl() . '/track/' . $shipment->tracking_token
            : null;

        // Load all attachments for this shipment in one query, then group
        // them by which tracking event (or the shipment itself) they belong
        // to. Admin sees every attachment regardless of is_public.
        $allAttachments = ShipmentAttachment::where('shipment_id', $id)->get();
        $shipmentAttachments = $allAttachments
            ->where('attachable_type', 'shipment')
            ->map(fn ($a) => $this->formatAttachment($a))
            ->values();
        $trackingAttachmentsByEvent = $allAttachments
            ->where('attachable_type', 'tracking')
            ->groupBy('attachable_id');

        // Append attachments to each tracking event
        $tracking = $tracking->map(function ($event) use ($trackingAttachmentsByEvent) {
            $event->attachments = ($trackingAttachmentsByEvent->get($event->id) ?? collect())
                ->map(fn ($a) => $this->formatAttachment($a))
                ->values();
            return $event;
        });

        return response()->json(array_merge((array) $shipment, [
            // FIX: was nested as `'shipment' => array_merge(..., ['attachments' => ...])`.
            // Every other field on this response (tracking_history, tracking_url,
            // milestone_index, status_labels) is flat at the top level, and the
            // frontend's ShipmentDetail type + every read site (detail.tracking_url,
            // detail.tracking_history, etc) expects that same flat shape. Attachments
            // were the one field accidentally buried under a 'shipment' key that
            // nothing on the frontend ever read from - this is why the Documents
            // card and the Edit modal's existing-files list both stayed empty.
            'attachments'      => $shipmentAttachments,
            'tracking_history' => $tracking,
            'tracking_url'     => $trackingUrl,
            'milestone_index'  => $this->milestoneIndex($shipment->status),
            'status_labels'    => self::STATUS_LABELS,
        ]));
    }

    // =========================================================================
    // POST /admin/orders/{orderId}/shipments  - create shipment for an order
    // =========================================================================

    public function create(Request $request, $orderId)
    {
        $validated = $request->validate([
            'carrier'                 => 'required|string|max:100',
            'tracking_number'         => 'nullable|string|max:100',
            'carrier_tracking_url'    => 'nullable|url|max:500',
            'estimated_delivery_date' => 'nullable|date',
            'notes'                   => 'nullable|string',
            // Optional customer-facing description for the initial
            // "order_confirmed" tracking event this method creates below.
            // Distinct from `notes`, which is internal-only.
            'description'             => 'nullable|string|max:500',
        ]);

        $order = Order::findOrFail($orderId);

        if (!in_array($order->status, ['confirmed', 'shipped'])) {
            return response()->json(['message' => 'Order cannot be shipped in its current status.'], 422);
        }

        $existing = DB::table('order_shipments')
            ->where('order_id', $orderId)
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Order already has an active shipment.'], 422);
        }

        DB::beginTransaction();
        try {
            $token          = Str::uuid()->toString();
            $shipmentNumber = 'SHP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

            $shipmentId = DB::table('order_shipments')->insertGetId([
                'order_id'                => $orderId,
                'shipment_number'         => $shipmentNumber,
                'carrier'                 => $validated['carrier'],
                'tracking_number'         => $validated['tracking_number'] ?? null,
                'tracking_token'          => $token,
                'carrier_tracking_url'    => $validated['carrier_tracking_url'] ?? null,
                'status'                  => 'order_confirmed',
                'shipped_at'              => now(),
                'estimated_delivery_date' => $validated['estimated_delivery_date'] ?? null,
                'notes'                   => $validated['notes'] ?? null,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            // Initial tracking event - visible to customer.
            // Uses the staff-provided description if given (so the customer
            // sees a specific note like "Hand-delivered by our own rider
            // this Friday"), falling back to the generic default otherwise.
            DB::table('shipment_tracking')->insert([
                'shipment_id' => $shipmentId,
                'status'      => 'order_confirmed',
                'location'    => null,
                'description' => $validated['description'] ?? 'Order confirmed and shipment created.',
                'is_public'   => true,
                'added_by'    => $request->user()->id,
                'event_time'  => now(),
                'created_at'  => now(),
            ]);

            // Advance order to shipped
            DB::table('orders')->where('id', $orderId)->update([
                'status'     => 'shipped',
                'updated_at' => now(),
            ]);

            DB::commit();

            $shipment = DB::table('order_shipments')->find($shipmentId);

            // ── Audit log - linked to the order so it appears in the order trail ──
            try {
                ActivityLogService::log('shipment_created', $order, [
                    'shipment_id'     => $shipmentId,
                    'shipment_number' => $shipmentNumber,
                    'carrier'         => $validated['carrier'],
                    'tracking_number' => $validated['tracking_number'] ?? null,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'      => 'Shipment created successfully.',
                'shipment'     => $shipment,
                'tracking_url' => $this->frontendUrl() . '/track/' . $token,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create shipment.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PUT /admin/shipments/{id}  - update carrier/tracking details
    // =========================================================================

    public function update(Request $request, $id)
    {
        $shipment = DB::table('order_shipments')->find($id);
        if (!$shipment) return response()->json(['message' => 'Shipment not found'], 404);

        $validated = $request->validate([
            'carrier'                 => 'sometimes|string|max:100',
            'tracking_number'         => 'nullable|string|max:100',
            'carrier_tracking_url'    => 'nullable|url|max:500',
            'estimated_delivery_date' => 'nullable|date',
            'notes'                   => 'nullable|string',
            // The customer-facing description lives on the initial
            // "order_confirmed" tracking event, not on order_shipments itself -
            // there's no description column on the shipment record. Editing it
            // here updates that first event in place rather than creating a
            // new one, since this is a correction to the existing shipment,
            // not a new stage in the pipeline.
            'description'             => 'nullable|string|max:500',
        ]);

        $shipmentFields = array_diff_key($validated, ['description' => null]);
        if (!empty($shipmentFields)) {
            DB::table('order_shipments')
                ->where('id', $id)
                ->update(array_merge($shipmentFields, ['updated_at' => now()]));
        }

        // Update the initial tracking event's description, if provided.
        // Looks specifically for the "order_confirmed" event since that's
        // the one created at shipment-creation time with the staff-provided
        // description (see create() above) - other events are separate
        // pipeline stages and aren't touched by this endpoint.
        //
        // Resolved as find-then-update-by-id rather than a single UPDATE...
        // LIMIT 1, since LIMIT inside UPDATE isn't portable to PostgreSQL
        // (this app's DB driver) the way it is on MySQL.
        if ($request->has('description')) {
            $firstEventId = DB::table('shipment_tracking')
                ->where('shipment_id', $id)
                ->where('status', 'order_confirmed')
                ->orderBy('event_time', 'asc')
                ->value('id');

            if ($firstEventId) {
                DB::table('shipment_tracking')
                    ->where('id', $firstEventId)
                    ->update(['description' => $validated['description'] ?? null]);
            }
        }

        $firstEventDescription = DB::table('shipment_tracking')
            ->where('shipment_id', $id)
            ->where('status', 'order_confirmed')
            ->orderBy('event_time', 'asc')
            ->value('description');

        $shipmentAttachments = ShipmentAttachment::where('shipment_id', $id)
            ->where('attachable_type', 'shipment')
            ->get()
            ->map(fn ($a) => $this->formatAttachment($a))
            ->values();

        return response()->json([
            'message'     => 'Shipment updated.',
            'shipment'    => array_merge((array) DB::table('order_shipments')->find($id), [
                'description' => $firstEventDescription,
                'attachments'  => $shipmentAttachments,
            ]),
        ]);
    }

    // =========================================================================
    // POST /admin/shipments/{id}/tracking  - add a tracking event
    // =========================================================================

    public function addTracking(Request $request, $id)
    {
        $allStatuses = implode(',', array_keys(self::STATUS_LABELS));

        $validated = $request->validate([
            'status'      => "required|in:{$allStatuses}",
            'location'    => 'nullable|string|max:255',
            // FIX: was 'required' - the description shown to the customer
            // for each stage is optional, per product requirement. Staff can
            // add a tracking event with just a status + location and no
            // customer-facing note if they have nothing specific to say.
            'description' => 'nullable|string|max:500',
            'event_time'  => 'nullable|date',
            'is_public'   => 'boolean',   // default true
        ]);

        $shipment = DB::table('order_shipments')->find($id);
        if (!$shipment) return response()->json(['message' => 'Shipment not found'], 404);

        if (in_array($shipment->status, ['delivered', 'cancelled'])) {
            return response()->json(['message' => 'Cannot add events to a delivered or cancelled shipment.'], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('shipment_tracking')->insert([
                'shipment_id' => $id,
                'status'      => $validated['status'],
                'location'    => $validated['location'] ?? null,
                'description' => $validated['description'] ?? null,
                'is_public'   => $validated['is_public'] ?? true,
                'added_by'    => $request->user()->id,
                'event_time'  => $validated['event_time'] ?? now(),
                'created_at'  => now(),
            ]);

            // Advance the shipment status
            DB::table('order_shipments')->where('id', $id)->update([
                'status'     => $validated['status'],
                'updated_at' => now(),
            ]);

            // Auto-complete the order on delivery
            if ($validated['status'] === 'delivered') {
                DB::table('order_shipments')->where('id', $id)->update(['delivered_at' => now()]);
                DB::table('orders')->where('id', $shipment->order_id)->update([
                    'status'       => 'completed',
                    'completed_at' => now(),
                    'updated_at'   => now(),
                ]);
            }

            DB::commit();

            // Notify customer (if linked to an account) and admins of status change
            try {
                $order = \App\Models\Order::find($shipment->order_id);
                NotificationService::shipmentStatusChanged(
                    (int) $shipment->order_id,          // orderId
                    $order?->order_number ?? '',        // orderNumber
                    $validated['status'],               // newStatus
                    $order?->user_id                    // customerId (?int)
                );
                if ($order) {
                    ActivityLogService::log('shipment_tracking_added', $order, [
                        'shipment_id'  => $id,
                        'new_status'   => $validated['status'],
                        'description'  => $validated['description'] ?? null,
                        'location'     => $validated['location'] ?? null,
                    ]);
                }
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Tracking event added.',
                'status'  => $validated['status'],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to add tracking event.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /admin/shipments/{id}/tracking  - full history (admin)
    // =========================================================================

    public function getTracking($id)
    {
        $shipment = DB::table('order_shipments')->find($id);
        if (!$shipment) return response()->json(['message' => 'Shipment not found'], 404);

        $tracking = DB::table('shipment_tracking as t')
            ->leftJoin('users as u', 't.added_by', '=', 'u.id')
            ->where('t.shipment_id', $id)
            ->orderBy('t.event_time', 'asc')
            ->select('t.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as added_by_name"))
            ->get();

        return response()->json([
            'shipment' => $shipment,
            'tracking' => $tracking,
        ]);
    }

    // =========================================================================
    // GET /track/{token}  - PUBLIC, no auth required
    //
    // Returns only is_public=true events. Rate-limited via middleware.
    // =========================================================================

    public function publicTrack(Request $request, string $token)
    {
        $shipment = DB::table('order_shipments as s')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->where('s.tracking_token', $token)
            ->select(
                's.id',
                's.shipment_number',
                's.carrier',
                's.tracking_number',
                's.carrier_tracking_url',
                's.status',
                's.shipped_at',
                's.delivered_at',
                's.estimated_delivery_date',
                's.notes',
                'o.order_number',
                'o.customer_first_name',
                'o.shipping_city',
                'o.shipping_country_code',
                'o.currency_code',
                'o.customer_email',
            )
            ->first();

        if (!$shipment) {
            return response()->json(['message' => 'Tracking information not found.'], 404);
        }

        $events = DB::table('shipment_tracking')
            ->where('shipment_id', $shipment->id)
            ->where('is_public', true)
            ->orderBy('event_time', 'asc')
            ->select('id', 'status', 'location', 'description', 'event_time')
            ->get();

        // Load every public attachment for this shipment's events in one query,
        // then group by tracking event id - avoids N+1 queries per event.
        $publicTrackingAttachments = ShipmentAttachment::where('shipment_id', $shipment->id)
            ->where('attachable_type', 'tracking')
            ->where('is_public', true)
            ->get()
            ->groupBy('attachable_id');

        $events = $events->map(function ($e) use ($shipment, $token, $publicTrackingAttachments) {
            $attachments = ($publicTrackingAttachments->get($e->id) ?? collect())
                ->map(function ($a) use ($token) {
                    $mimeType = $this->resolveMimeType($a->mime_type, $a->original_name);
                    return [
                        'url'       => $this->buildPublicAttachmentUrl($token, $a->id),
                        'name'      => $a->original_name,
                        'mime_type' => $mimeType,
                        'is_image'  => $mimeType ? str_starts_with($mimeType, 'image/') : false,
                    ];
                })
                ->values();

            return [
                'status'      => $e->status,
                'label'       => self::STATUS_LABELS[$e->status] ?? ucfirst(str_replace('_', ' ', $e->status)),
                'location'    => $e->location,
                // Only surface the description when staff actually wrote one -
                // it's optional, so an empty/null value should not render as
                // a blank line on the customer's tracking page.
                'description' => $e->description !== null && trim($e->description) !== '' ? $e->description : null,
                'event_time'  => $e->event_time,
                'attachments' => $attachments,
            ];
        });

        // Business branding — DEFAULTS merged with DB values via shared helper
        $settings = \App\Http\Controllers\Api\SettingController::getAll();

        // Shipment-level attachments the customer is allowed to see (e.g. a
        // public copy of the waybill), separate from per-event attachments.
        $publicShipmentAttachments = ShipmentAttachment::where('shipment_id', $shipment->id)
            ->where('attachable_type', 'shipment')
            ->where('is_public', true)
            ->get()
            ->map(function ($a) use ($token) {
                $mimeType = $this->resolveMimeType($a->mime_type, $a->original_name);
                return [
                    'url'       => $this->buildPublicAttachmentUrl($token, $a->id),
                    'name'      => $a->original_name,
                    'mime_type' => $mimeType,
                    'is_image'  => $mimeType ? str_starts_with($mimeType, 'image/') : false,
                ];
            })
            ->values();

        return response()->json([
            'shipment'         => $shipment,
            'shipment_attachments' => $publicShipmentAttachments,
            'events'           => $events,
            'milestone_index'  => $this->milestoneIndex($shipment->status),
            'milestones'       => $this->buildMilestones($shipment->status),
            'status_label'     => self::STATUS_LABELS[$shipment->status] ?? $shipment->status,
            'business_name'    => $settings['app_name'],
            'business_tagline' => $settings['app_tagline'] ?: null,
            'business_logo'    => $settings['app_logo_url'] ?: null,
        ]);
    }

    // =========================================================================
    // GET /track/{token}/attachments/{attachmentId}  - PUBLIC, no auth required
    //
    // Serves a single attachment for the customer tracking page. Strictly
    // gated to is_public=true AND the attachment must belong to the shipment
    // identified by this exact tracking token - this is the only attachment
    // route a customer can reach without an admin session, so the public/
    // private check here is the sole thing standing between "marked private"
    // and "downloadable by anyone with the link".
    // =========================================================================

    public function publicServeAttachment(Request $request, string $token, int $attachmentId)
    {
        $shipment = DB::table('order_shipments')->where('tracking_token', $token)->first();
        if (!$shipment) {
            abort(404, 'Tracking information not found.');
        }

        $attachment = ShipmentAttachment::where('shipment_id', $shipment->id)
            ->where('id', $attachmentId)
            ->where('is_public', true)
            ->first();

        if (!$attachment) {
            abort(404, 'Attachment not found');
        }

        return $this->streamAttachment($attachment->path, $attachment->original_name, $request->boolean('download'));
    }

    // =========================================================================
    // POST /track/{token}/query  - customer submits a query from tracking page
    // No auth required. Rate-limited.
    // =========================================================================

    public function submitQuery(Request $request, string $token)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:100',
            'email'   => 'required|email|max:255',
            'message' => 'required|string|max:1000',
        ]);

        $row = DB::table('order_shipments as s')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->where('s.tracking_token', $token)
            ->select('s.id', 'o.order_number', 'o.id as order_id', 'o.customer_notes')
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Tracking link not found.'], 404);
        }

        // Append to the order's customer_notes column so it surfaces on the
        // Order Detail page for admin staff to action.
        $timestamp  = now()->format('d M Y H:i');
        $entry      = "[Customer query - {$validated['name']} <{$validated['email']}> - {$timestamp}]\n{$validated['message']}";
        $existing   = $row->customer_notes ? $row->customer_notes . "\n\n" : '';

        DB::table('orders')->where('id', $row->order_id)->update([
            'customer_notes' => $existing . $entry,
            'updated_at'     => now(),
        ]);

        return response()->json([
            'message' => 'Your query has been received. We will get back to you shortly.',
        ]);
    }

    // =========================================================================
    // POST /admin/shipments/{id}/mark-delivered
    // =========================================================================

    public function markDelivered(Request $request, $id)
    {
        $validated = $request->validate([
            'delivered_to' => 'nullable|string|max:255',
            'signature'    => 'nullable|string',
            'notes'        => 'nullable|string',
        ]);

        $shipment = DB::table('order_shipments')->find($id);
        if (!$shipment) return response()->json(['message' => 'Shipment not found'], 404);

        if ($shipment->status === 'delivered') {
            return response()->json(['message' => 'Shipment already marked as delivered.'], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('order_shipments')->where('id', $id)->update([
                'status'            => 'delivered',
                'delivered_at'      => now(),
                'delivered_to'      => $validated['delivered_to'] ?? null,
                'delivery_signature'=> $validated['signature'] ?? null,
                'delivery_notes'    => $validated['notes'] ?? null,
                'updated_at'        => now(),
            ]);

            DB::table('shipment_tracking')->insert([
                'shipment_id' => $id,
                'status'      => 'delivered',
                'description' => 'Package delivered'
                    . ($validated['delivered_to'] ? ' to ' . $validated['delivered_to'] : '') . '.',
                'is_public'   => true,
                'added_by'    => $request->user()->id,
                'event_time'  => now(),
                'created_at'  => now(),
            ]);

            // Delivery means goods are in customer's hands - order moves to 'delivered',
            // not 'completed'. Staff explicitly sets 'completed' to close the order.
            DB::table('orders')->where('id', $shipment->order_id)->update([
                'status'     => 'delivered',
                'updated_at' => now(),
            ]);

            DB::commit();

            try {
                $order = \App\Models\Order::find($shipment->order_id);
                if ($order) {
                    ActivityLogService::log('shipment_delivered', $order, [
                        'shipment_id'  => $id,
                        'delivered_to' => $validated['delivered_to'] ?? null,
                        'notes'        => $validated['notes'] ?? null,
                    ]);
                }
            } catch (\Exception) {}

            return response()->json(['message' => 'Shipment marked as delivered.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to mark as delivered.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /admin/shipments/{id}/cancel
    // =========================================================================

    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $shipment = DB::table('order_shipments')->find($id);
        if (!$shipment) return response()->json(['message' => 'Shipment not found'], 404);

        if (in_array($shipment->status, ['delivered', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel shipment in current status.'], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('order_shipments')->where('id', $id)->update([
                'status'              => 'cancelled',
                'cancellation_reason' => $validated['reason'],
                'cancelled_at'        => now(),
                'updated_at'          => now(),
            ]);

            DB::table('shipment_tracking')->insert([
                'shipment_id' => $id,
                'status'      => 'cancelled',
                'description' => 'Shipment cancelled: ' . $validated['reason'],
                'is_public'   => false,   // Internal note - don't show on public page
                'added_by'    => $request->user()->id,
                'event_time'  => now(),
                'created_at'  => now(),
            ]);

            // Revert order to 'confirmed' - it was confirmed before the shipment,
            // so cancelling the shipment returns it to that state, not 'processing'.
            DB::table('orders')->where('id', $shipment->order_id)->update([
                'status'     => 'confirmed',
                'updated_at' => now(),
            ]);

            DB::commit();

            try {
                $order = \App\Models\Order::find($shipment->order_id);
                if ($order) {
                    ActivityLogService::log('shipment_cancelled', $order, [
                        'shipment_id' => $id,
                        'reason'      => $validated['reason'],
                    ]);
                }
            } catch (\Exception) {}

            return response()->json(['message' => 'Shipment cancelled.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to cancel shipment.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /admin/shipments/{id}/upload-attachment
    // Attach one or more documents/photos to the shipment record itself
    // (e.g. waybill). Same multi-file + per-file is_public pattern as the
    // tracking-event upload endpoint below.
    // =========================================================================

    public function uploadShipmentAttachment(Request $request, $id)
    {
        $request->validate([
            'attachment'     => ['sometimes', 'file', 'max:' . self::MAX_ATTACHMENT_KB, 'mimetypes:' . implode(',', self::ALLOWED_ATTACHMENT_MIMES)],
            'attachments'    => ['sometimes', 'array'],
            'attachments.*'  => ['file', 'max:' . self::MAX_ATTACHMENT_KB, 'mimetypes:' . implode(',', self::ALLOWED_ATTACHMENT_MIMES)],
            'is_public'      => ['sometimes'],
        ]);

        $shipment = DB::table('order_shipments')->find($id);
        if (!$shipment) {
            return response()->json(['message' => 'Shipment not found'], 404);
        }

        $files = $request->hasFile('attachments')
            ? $request->file('attachments')
            : ($request->hasFile('attachment') ? [$request->file('attachment')] : []);

        if (empty($files)) {
            return response()->json(['message' => 'No file provided.'], 422);
        }

        $isPublicInput = $request->input('is_public', false);
        $created = [];

        foreach ($files as $i => $file) {
            $extension = $file->getClientOriginalExtension();
            $filename  = Str::uuid() . '.' . $extension;
            $path      = 'shipments/' . now()->format('Y/m') . '/' . $filename;

            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

            $isPublic = is_array($isPublicInput)
                ? filter_var($isPublicInput[$i] ?? false, FILTER_VALIDATE_BOOLEAN)
                : filter_var($isPublicInput, FILTER_VALIDATE_BOOLEAN);

            $attachment = ShipmentAttachment::create([
                'attachable_type' => 'shipment',
                'attachable_id'   => $id,
                'shipment_id'     => $id,
                'path'            => $path,
                // FIX: never store or display the uploader's real filename -
                // generate a random one instead, keeping only the extension
                // so downloads still open correctly. The original name is
                // discarded entirely; it is not recoverable after upload.
                'original_name'   => $this->randomDisplayName($extension),
                'mime_type'       => $file->getMimeType(),
                'is_public'       => $isPublic,
                'uploaded_by'     => $request->user()->id,
            ]);

            $created[] = $this->formatAttachment($attachment);
        }

        return response()->json([
            'message'     => count($created) === 1 ? 'Attachment uploaded.' : count($created) . ' attachments uploaded.',
            'attachments' => $created,
        ]);
    }

    // =========================================================================
    // POST /admin/shipments/{id}/tracking/{trackingId}/upload-attachment
    // Attach one or more photos/documents to a specific tracking event.
    //
    // Accepts either a single `attachment` file (back-compat with existing
    // callers) or multiple `attachments[]` files. Each file's visibility is
    // controlled by a matching `is_public` flag - sent either as a single
    // `is_public` value (applied to all files) or `is_public[]` (one value
    // per file, by index).
    // =========================================================================

    public function uploadTrackingAttachment(Request $request, $id, $trackingId)
    {
        $request->validate([
            'attachment'     => ['sometimes', 'file', 'max:' . self::MAX_ATTACHMENT_KB, 'mimetypes:' . implode(',', self::ALLOWED_ATTACHMENT_MIMES)],
            'attachments'    => ['sometimes', 'array'],
            'attachments.*'  => ['file', 'max:' . self::MAX_ATTACHMENT_KB, 'mimetypes:' . implode(',', self::ALLOWED_ATTACHMENT_MIMES)],
            'is_public'      => ['sometimes'],   // bool, or array of bools (one per file)
        ]);

        $event = DB::table('shipment_tracking')
            ->where('id', $trackingId)
            ->where('shipment_id', $id)
            ->first();

        if (!$event) {
            return response()->json(['message' => 'Tracking event not found'], 404);
        }

        $files = $request->hasFile('attachments')
            ? $request->file('attachments')
            : ($request->hasFile('attachment') ? [$request->file('attachment')] : []);

        if (empty($files)) {
            return response()->json(['message' => 'No file provided.'], 422);
        }

        $isPublicInput = $request->input('is_public', false);
        $created = [];

        foreach ($files as $i => $file) {
            $extension = $file->getClientOriginalExtension();
            $filename  = Str::uuid() . '.' . $extension;
            $path      = 'shipments/tracking/' . now()->format('Y/m') . '/' . $filename;

            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

            // Resolve per-file visibility: array → indexed lookup, scalar → applies to all.
            $isPublic = is_array($isPublicInput)
                ? filter_var($isPublicInput[$i] ?? false, FILTER_VALIDATE_BOOLEAN)
                : filter_var($isPublicInput, FILTER_VALIDATE_BOOLEAN);

            $attachment = ShipmentAttachment::create([
                'attachable_type' => 'tracking',
                'attachable_id'   => $trackingId,
                'shipment_id'     => $id,
                'path'            => $path,
                // FIX: never store or display the uploader's real filename -
                // generate a random one instead, keeping only the extension.
                'original_name'   => $this->randomDisplayName($extension),
                'mime_type'       => $file->getMimeType(),
                'is_public'       => $isPublic,
                'uploaded_by'     => $request->user()->id,
            ]);

            $created[] = $this->formatAttachment($attachment);
        }

        return response()->json([
            'message'     => count($created) === 1 ? 'Attachment uploaded.' : count($created) . ' attachments uploaded.',
            'attachments' => $created,
        ]);
    }

    // =========================================================================
    // DELETE /admin/shipments/{id}/attachments/{attachmentId}
    // Remove a single attachment (shipment-level or tracking-level).
    // =========================================================================

    public function deleteAttachment(Request $request, $id, $attachmentId)
    {
        $attachment = ShipmentAttachment::where('shipment_id', $id)
            ->where('id', $attachmentId)
            ->first();

        if (!$attachment) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return response()->json(['message' => 'Attachment removed.']);
    }

    // =========================================================================
    // PATCH /admin/shipments/{id}/attachments/{attachmentId}
    // Toggle whether a single attachment is visible on the public tracking page.
    // =========================================================================

    public function updateAttachmentVisibility(Request $request, $id, $attachmentId)
    {
        $validated = $request->validate([
            'is_public' => 'required|boolean',
        ]);

        $attachment = ShipmentAttachment::where('shipment_id', $id)
            ->where('id', $attachmentId)
            ->first();

        if (!$attachment) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        $attachment->update(['is_public' => $validated['is_public']]);

        return response()->json([
            'message'    => 'Attachment visibility updated.',
            'attachment' => $this->formatAttachment($attachment),
        ]);
    }

    // =========================================================================
    // GET /admin/shipments/{id}/attachment
    // GET /admin/shipments/{id}/tracking/{trackingId}/attachment
    // Serve the attachment - generates a temporary signed URL or streams the file.
    // =========================================================================

    // =========================================================================
    // GET /admin/shipments/{id}/attachments/{attachmentId}
    // GET /admin/shipments/{id}/tracking/{trackingId}/attachments/{attachmentId}
    // Serve a specific attachment - generates a temporary signed URL or
    // streams the file. Both routes resolve to the same lookup since every
    // attachment row already carries shipment_id regardless of which parent
    // (shipment or tracking event) it's attached to.
    // =========================================================================

    public function serveShipmentAttachment(Request $request, $id, $attachmentId)
    {
        $attachment = ShipmentAttachment::where('shipment_id', $id)
            ->where('id', $attachmentId)
            ->first();

        if (!$attachment) {
            return response()->json(['message' => 'No attachment found'], 404);
        }
        return $this->streamAttachment($attachment->path, $attachment->original_name, $request->boolean('download'));
    }

    public function serveTrackingAttachment(Request $request, $id, $trackingId, $attachmentId)
    {
        $attachment = ShipmentAttachment::where('shipment_id', $id)
            ->where('attachable_type', 'tracking')
            ->where('attachable_id', $trackingId)
            ->where('id', $attachmentId)
            ->first();

        if (!$attachment) {
            return response()->json(['message' => 'No attachment found'], 404);
        }
        return $this->streamAttachment($attachment->path, $attachment->original_name, $request->boolean('download'));
    }

    // =========================================================================
    // GET /admin/shipments/{id}/audit-log
    // =========================================================================

    public function auditLog($id)
    {
        // 404 guard
        $exists = DB::table('order_shipments')->where('id', $id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Shipment not found'], 404);
        }

        $logs = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->where(function ($q) use ($id) {
                $q->where(function ($q2) use ($id) {
                    $q2->where('al.subject_type', \App\Models\OrderShipment::class)
                       ->where('al.subject_id', $id);
                })->orWhere(function ($q2) use ($id) {
                    // Also pick up activity logged against the parent order that references this shipment
                    $q2->whereRaw("al.properties::text LIKE ?", ["%\"shipment_id\":{$id}%"]);
                });
            })
            ->orderBy('al.created_at', 'desc')
            ->select(
                'al.id', 'al.event', 'al.action', 'al.description',
                'al.properties', 'al.ip_address', 'al.created_at',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name"),
                'u.email as actor_email'
            )
            ->get()
            ->map(function ($log) {
                $props = $log->properties ? json_decode($log->properties, true) : [];
                $labels = [
                    'created'                  => 'Shipment Created',
                    'shipment_created'         => 'Shipment Created',
                    'status_changed'           => 'Status Changed',
                    'shipment_tracking_added'  => 'Tracking Event Added',
                    'shipment_delivered'       => 'Marked Delivered',
                    'shipment_cancelled'       => 'Shipment Cancelled',
                    'updated'                  => 'Shipment Updated',
                ];
                $event = $log->event ?? $log->action ?? '';
                return [
                    'id'          => $log->id,
                    'event'       => $event,
                    'label'       => $labels[$event] ?? ucfirst(str_replace('_', ' ', $event)),
                    'description' => $log->description,
                    'properties'  => $props,
                    'actor_name'  => $log->actor_name,
                    'actor_email' => $log->actor_email,
                    'ip_address'  => $log->ip_address,
                    'created_at'  => $log->created_at,
                ];
            });

        return response()->json(['logs' => $logs]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function milestoneIndex(string $status): int
    {
        $idx = array_search($status, self::MILESTONE_STATUSES);
        return $idx !== false ? (int) $idx : -1;
    }

    private function buildMilestones(string $currentStatus): array
    {
        $currentIdx = $this->milestoneIndex($currentStatus);
        $milestones  = [];

        foreach (self::MILESTONE_STATUSES as $i => $s) {
            $milestones[] = [
                'status'    => $s,
                'label'     => self::STATUS_LABELS[$s],
                'state'     => $i < $currentIdx ? 'done'
                    : ($i === $currentIdx ? 'active' : 'upcoming'),
            ];
        }

        return $milestones;
    }

    /**
     * Return the frontend base URL for public-facing links (e.g. tracking pages).
     *
     * Uses config('app.frontend_url') - NOT env() directly - so it works correctly
     * whether or not the Laravel config cache is active. Ensure config/app.php has:
     *   'frontend_url' => env('FRONTEND_URL', 'http://localhost:3002'),
     */
    private function frontendUrl(): string
    {
        return rtrim(config('app.frontend_url', 'http://localhost:3002'), '/');
    }

    private function buildAttachmentUrl(string $type, int $shipmentId, ?int $trackingId, int $attachmentId): string
    {
        $base = config('app.url') . '/api/v1/admin/shipments/' . $shipmentId;
        return $type === 'shipment'
            ? $base . '/attachments/' . $attachmentId
            : $base . '/tracking/' . $trackingId . '/attachments/' . $attachmentId;
    }

    /**
     * Public (no-auth) attachment URL for the customer tracking page.
     * Routes through /track/{token}/attachments/{attachmentId}, NOT the
     * /admin/... routes - the customer has no admin session, so a link
     * built with buildAttachmentUrl() above would 401 for them.
     */
    private function buildPublicAttachmentUrl(string $token, int $attachmentId): string
    {
        return config('app.url') . '/api/v1/track/' . $token . '/attachments/' . $attachmentId;
    }

    /**
     * Generates a random, non-identifying display name for an uploaded file,
     * keeping only the extension so downloads still behave sensibly (e.g.
     * "k3j9xQ2mZ7pL.pdf" instead of the customer's or staff member's actual
     * filename, which might contain personal info, order numbers, or simply
     * be confusing clutter like "Screenshot 2026-06-15 142738.png").
     */
    private function randomDisplayName(?string $extension): string
    {
        $name = Str::random(12);
        return $extension ? $name . '.' . strtolower($extension) : $name;
    }

    /**
     * Resolves a usable mime type for an attachment, falling back to guessing
     * from the file extension when mime_type is null in the database - this
     * affects every attachment uploaded before the mime_type column existed
     * (see the 2026_06_21_000002 migration, which backfilled what it could
     * read from disk but left it null for any file that no longer existed at
     * its original path). Without this fallback, every pre-migration image
     * attachment silently fails to preview/thumbnail despite clearly being
     * an image, since is_image/is_pdf both derive from this value.
     */
    private function resolveMimeType(?string $storedMimeType, string $filename): ?string
    {
        if ($storedMimeType) {
            return $storedMimeType;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'pdf'         => 'application/pdf',
            default       => null,
        };
    }

    /**
     * Shape a ShipmentAttachment for API responses (admin views).
     */
    private function formatAttachment(ShipmentAttachment $attachment): array
    {
        $mimeType = $this->resolveMimeType($attachment->mime_type, $attachment->original_name);

        return [
            'id'            => $attachment->id,
            'name'          => $attachment->original_name,
            'mime_type'     => $mimeType,
            // Convenience flag so the frontend doesn't need its own mime-type
            // parsing logic to decide whether to render a thumbnail.
            'is_image'      => $mimeType ? str_starts_with($mimeType, 'image/') : false,
            'is_public'     => $attachment->is_public,
            'url'           => $attachment->attachable_type === 'shipment'
                ? $this->buildAttachmentUrl('shipment', $attachment->shipment_id, null, $attachment->id)
                : $this->buildAttachmentUrl('tracking', $attachment->shipment_id, $attachment->attachable_id, $attachment->id),
            'uploaded_at'   => $attachment->created_at,
        ];
    }

    private function streamAttachment(string $path, ?string $name, bool $forceDownload = false): \Symfony\Component\HttpFoundation\Response
    {
        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'Attachment not found');
        }

        $disposition = $forceDownload ? 'attachment' : 'inline';

        // FIX: this used to try Storage::disk('local')->temporaryUrl(...) first
        // and redirect() there if it succeeded. On this app's environment that
        // call DOES succeed (rather than throwing, as on a vanilla local disk),
        // producing a signed /storage/...?expires=...&signature=... URL that:
        //   1. bypasses this controller entirely - no auth check, no is_public
        //      gating, no random-filename masking, no Content-Disposition control
        //   2. isn't covered by the API's CORS configuration, since it's a static
        //      asset path outside the api/* route group
        //   3. 404s in practice on this setup anyway
        // This app only ever runs on the local disk, so there's no S3 case to
        // support - always stream the file directly through this controller.
        $content  = Storage::disk('local')->get($path);
        $mime     = Storage::disk('local')->mimeType($path);
        $filename = $name ?? basename($path);
        return response($content, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
        ]);
    }
}