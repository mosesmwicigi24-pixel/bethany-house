import { get, post, put, patch, del } from "./client";

// ── Types ─────────────────────────────────────────────────────────────────────

export type ShipmentStatus =
    | "order_confirmed"
    | "processing"
    | "ready_to_ship"
    | "picked_up"
    | "in_transit"
    | "out_for_delivery"
    | "delivery_attempted"
    | "delivered"
    | "exception"
    | "cancelled";

export const SHIPMENT_STATUS_LABELS: Record<ShipmentStatus, string> = {
    order_confirmed:     "Order Confirmed",
    processing:          "Processing",
    ready_to_ship:       "Ready to Ship",
    picked_up:           "Picked Up",
    in_transit:          "In Transit",
    out_for_delivery:    "Out for Delivery",
    delivery_attempted:  "Delivery Attempted",
    delivered:           "Delivered",
    exception:           "Exception",
    cancelled:           "Cancelled",
};

// Ordered pipeline - used to build the milestone progress bar
export const TRACKING_MILESTONES: ShipmentStatus[] = [
    "order_confirmed",
    "processing",
    "ready_to_ship",
    "picked_up",
    "in_transit",
    "out_for_delivery",
    "delivery_attempted",
    "delivered",
];

export interface ShipmentAttachment {
    id: number;
    /** Random, non-identifying display name (e.g. "k3j9xQ2mZ7pL.pdf") - the
     *  uploader's real filename is never stored or shown. */
    name: string;
    mime_type: string | null;
    /** True when mime_type starts with "image/" - drives thumbnail vs. icon rendering. */
    is_image: boolean;
    is_public: boolean;
    url: string;
    uploaded_at: string;
}

export interface TrackingEvent {
    id?: number;
    status: ShipmentStatus;
    label?: string;
    location?: string | null;
    /** Optional - staff are not required to add a customer-facing note per stage. */
    description?: string | null;
    event_time: string;
    is_public?: boolean;
    added_by?: number | null;
    added_by_name?: string | null;
    /** Admin view: every attachment on this event, public or not. */
    attachments?: ShipmentAttachment[];
}

export interface TrackingMilestone {
    status: ShipmentStatus;
    label: string;
    state: "done" | "active" | "upcoming";
}

export interface Shipment {
    id: number;
    order_id: number;
    order_number: string;
    shipment_number: string;
    carrier: string;
    tracking_number?: string | null;
    /** UUID used in public tracking URL */
    tracking_token?: string | null;
    carrier_tracking_url?: string | null;
    status: ShipmentStatus;
    shipped_at: string;
    delivered_at?: string | null;
    estimated_delivery_date?: string | null;
    notes?: string | null;
    outlet_name?: string | null;
    customer_first_name?: string | null;
    customer_last_name?: string | null;
    customer_email?: string | null;
    customer_phone?: string | null;
}

export interface ShipmentDetail extends Shipment {
    tracking_history: TrackingEvent[];
    tracking_url?: string | null;
    milestone_index: number;
    status_labels: Record<string, string>;
    /** Shipment-level attachments (e.g. a waybill) - admin view, public or not. */
    attachments?: ShipmentAttachment[];
}

/** A single tracking-stage event as shaped for the public tracking page. */
export interface PublicTrackingEvent {
    status: ShipmentStatus;
    label: string;
    location?: string | null;
    /** Null when staff didn't add a note for this stage. */
    description: string | null;
    event_time: string;
    /** Only attachments the staff marked as customer-visible for this event. */
    attachments: { url: string; name: string; mime_type: string | null; is_image: boolean }[];
}

/** Response from the public /track/{token} endpoint - no auth required */
export interface PublicTrackingData {
    shipment: {
        id: number;
        order_number: string;
        carrier: string;
        tracking_number?: string | null;
        carrier_tracking_url?: string | null;
        status: ShipmentStatus;
        shipped_at: string;
        delivered_at?: string | null;
        estimated_delivery_date?: string | null;
        customer_first_name?: string | null;
        shipping_city?: string | null;
        shipping_country_code?: string | null;
    };
    /** Public shipment-level attachments (e.g. a customer-facing copy of the waybill). */
    shipment_attachments: { url: string; name: string; mime_type: string | null; is_image: boolean }[];
    events: PublicTrackingEvent[];
    milestone_index: number;
    milestones: TrackingMilestone[];
    status_label: string;
    /** Business branding — pulled from system settings on the backend */
    business_name:    string;
    business_tagline: string | null;
    business_logo:    string | null;
}

// ── API ───────────────────────────────────────────────────────────────────────

export const shipmentsApi = {
    list: (params?: Record<string, string>) =>
        get<{ data: Shipment[]; meta: { total: number; current_page: number; last_page: number } }>(
            "/v1/admin/shipments",
            { params },
        ),

    get: (id: number) =>
        get<ShipmentDetail>(`/v1/admin/shipments/${id}`),

    /** Create a shipment for an order */
    create: (
        orderId: number,
        data: {
            carrier: string;
            tracking_number?: string;
            carrier_tracking_url?: string;
            estimated_delivery_date?: string;
            shipped_from_outlet_id?: number;
            notes?: string;
            /** Optional - shown to the customer on the initial tracking event. */
            description?: string;
        },
    ) =>
        post<{ message: string; shipment: Shipment; tracking_url: string }>(
            `/v1/admin/orders/${orderId}/shipments`,
            data,
        ),

    update: (
        id: number,
        data: {
            carrier?: string;
            tracking_number?: string;
            carrier_tracking_url?: string;
            estimated_delivery_date?: string;
            notes?: string;
            /** Lives on the initial "order_confirmed" tracking event, not the
             *  shipment record itself - the backend writes it there. */
            description?: string;
        },
    ) =>
        put<{ message: string; shipment: Shipment & { description?: string | null; attachments?: ShipmentAttachment[] } }>(
            `/v1/admin/shipments/${id}`,
            data,
        ),

    /** Add a tracking event (admin only) */
    addTracking: (
        id: number,
        data: {
            status: ShipmentStatus;
            /** Optional - not every stage needs a customer-facing note. */
            description?: string;
            location?: string;
            event_time?: string;
            is_public?: boolean;
        },
    ) =>
        post<{ message: string; status: ShipmentStatus }>(
            `/v1/admin/shipments/${id}/tracking`,
            data,
        ),

    getTracking: (id: number) =>
        get<{ shipment: Shipment; tracking: TrackingEvent[] }>(
            `/v1/admin/shipments/${id}/tracking`,
        ),

    markDelivered: (
        id: number,
        data?: { delivered_to?: string; signature?: string; notes?: string },
    ) =>
        post<{ message: string }>(`/v1/admin/shipments/${id}/mark-delivered`, data ?? {}),

    cancel: (id: number, reason: string) =>
        post<{ message: string }>(`/v1/admin/shipments/${id}/cancel`, { reason }),

    /**
     * Upload one or more documents/photos attached to the shipment itself
     * (e.g. a waybill). Each file's `isPublic` controls whether it appears
     * on the customer-facing tracking page.
     */
    uploadAttachment: (
        shipmentId: number,
        files: { file: File; isPublic: boolean }[],
    ) => {
        const form = new FormData();
        files.forEach(({ file }) => form.append("attachments[]", file));
        files.forEach(({ isPublic }) => form.append("is_public[]", String(isPublic)));
        return post<{ message: string; attachments: ShipmentAttachment[] }>(
            `/v1/admin/shipments/${shipmentId}/upload-attachment`,
            form,
            { headers: { "Content-Type": undefined } },
        );
    },

    /**
     * Upload one or more photos/documents attached to a specific tracking
     * event. Each file's `isPublic` controls whether it appears on the
     * customer-facing tracking page for that stage.
     */
    uploadTrackingAttachment: (
        shipmentId: number,
        trackingId: number,
        files: { file: File; isPublic: boolean }[],
    ) => {
        const form = new FormData();
        files.forEach(({ file }) => form.append("attachments[]", file));
        files.forEach(({ isPublic }) => form.append("is_public[]", String(isPublic)));
        return post<{ message: string; attachments: ShipmentAttachment[] }>(
            `/v1/admin/shipments/${shipmentId}/tracking/${trackingId}/upload-attachment`,
            form,
            { headers: { "Content-Type": undefined } },
        );
    },

    /** Remove a single attachment (shipment-level or tracking-level). */
    deleteAttachment: (shipmentId: number, attachmentId: number) =>
        del<{ message: string }>(`/v1/admin/shipments/${shipmentId}/attachments/${attachmentId}`),

    /** Toggle whether a single attachment is visible on the public tracking page. */
    updateAttachmentVisibility: (shipmentId: number, attachmentId: number, isPublic: boolean) =>
        patch<{ message: string; attachment: ShipmentAttachment }>(
            `/v1/admin/shipments/${shipmentId}/attachments/${attachmentId}`,
            { is_public: isPublic },
        ),

    /** Public endpoint - no auth token needed, called from the tracking page */
    publicTrack: (token: string) =>
        get<PublicTrackingData>(`/v1/track/${token}`),

    /** Customer submits a query from the tracking page - no auth required */
    submitQuery: (token: string, data: { name: string; email: string; message: string }) =>
        post<{ message: string }>(`/v1/track/${token}/query`, data),
};