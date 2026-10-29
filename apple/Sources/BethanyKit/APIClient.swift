import Foundation

/// The one HTTP client for the Hub API.
///
/// Mirrors react-admin/src/api/client.ts and the conventions in
/// docs/api/README.md: bearer token, `X-Device-Id`, `X-Background-Request`
/// for polling, JSON in and out, and the status codes every screen must
/// understand (401 signed out, 423 PIN lock, 403 step-up or download hold,
/// 429 retry-after, 404 deleted vs not found).
///
/// The client itself is stateless about the session: it asks a `SessionAuth`
/// for the token and reports auth events back to it, which lets the session
/// store run the PIN-unlock and step-up dialogs and retry the request.
public actor APIClient {
    public let baseURL: URL
    public let device: DeviceIdentity
    private let session: URLSession
    private weak var auth: SessionAuth?

    public init(baseURL: URL, device: DeviceIdentity = .current(), auth: SessionAuth? = nil, configuration: URLSessionConfiguration = .default) {
        self.baseURL = baseURL
        self.device = device
        self.auth = auth
        configuration.timeoutIntervalForRequest = 30
        configuration.httpAdditionalHeaders = [
            "Accept": "application/json",
            "User-Agent": device.userAgent,
        ]
        configuration.waitsForConnectivity = false
        self.session = URLSession(configuration: configuration)
    }

    public func attach(auth: SessionAuth) { self.auth = auth }

    // MARK: Request description

    public enum Method: String, Sendable { case get = "GET", post = "POST", put = "PUT", patch = "PATCH", delete = "DELETE" }

    public struct Request: Sendable {
        public var method: Method
        /// Path relative to `/api`, e.g. "/v1/admin/auth/me".
        public var path: String
        public var query: [String: String?]
        public var body: Body?
        /// Marks polling (no human touched the screen): does not count as activity.
        public var background: Bool
        public var headers: [String: String]

        public enum Body: Sendable {
            case json(JSONValue)
            case encodable(any Encodable & Sendable)
            case multipart([MultipartPart])
        }

        public init(_ method: Method, _ path: String, query: [String: String?] = [:], body: Body? = nil, background: Bool = false, headers: [String: String] = [:]) {
            self.method = method
            self.path = path
            self.query = query
            self.body = body
            self.background = background
            self.headers = headers
        }
    }

    public struct MultipartPart: Sendable {
        public let name: String
        public let filename: String?
        public let mimeType: String?
        public let data: Data
        public init(name: String, data: Data, filename: String? = nil, mimeType: String? = nil) {
            self.name = name; self.data = data; self.filename = filename; self.mimeType = mimeType
        }
        public init(name: String, value: String) {
            self.init(name: name, data: Data(value.utf8))
        }
    }

    public struct Response: Sendable {
        public let status: Int
        public let headers: [String: String]
        public let data: Data
        public var json: JSONValue? { try? HubJSON.decoder.decode(JSONValue.self, from: data) }
        public func decode<T: Decodable>(_ type: T.Type) throws -> T { try HubJSON.decoder.decode(T.self, from: data) }
    }

    // MARK: Perform

    /// Sends the request, handling the session statuses once:
    /// 423 pin_locked → wait for the PIN unlock, retry (up to 3);
    /// 403 step_up_required → wait for step-up, retry (up to 2);
    /// 401 → session ended.
    public func send(_ request: Request) async throws -> Response {
        var pinRetries = 3
        var stepUpRetries = 2
        while true {
            let response = try await perform(request)
            switch response.status {
            case 200..<300:
                return response
            case 401:
                await auth?.sessionExpired()
                throw APIError(response)
            case 423 where response.json?["reason"]?.string == "pin_locked" && pinRetries > 0:
                pinRetries -= 1
                guard let auth, await auth.awaitPinUnlock() else { throw APIError(response) }
            case 403 where response.json?["code"]?.string == "step_up_required" && stepUpRetries > 0:
                stepUpRetries -= 1
                let challenge = StepUpChallenge(json: response.json)
                guard let auth, await auth.awaitStepUp(challenge) else { throw APIError(response) }
            case 403 where response.json?["code"]?.string == "download_approval_required":
                await auth?.downloadHeld(response.json)
                throw APIError(response)
            default:
                throw APIError(response)
            }
        }
    }

    public func get(_ path: String, query: [String: String?] = [:], background: Bool = false) async throws -> Response {
        try await send(Request(.get, path, query: query, background: background))
    }

    public func post(_ path: String, json: JSONValue = .object([:])) async throws -> Response {
        try await send(Request(.post, path, body: .json(json)))
    }

    public func post<T: Encodable & Sendable>(_ path: String, body: T) async throws -> Response {
        try await send(Request(.post, path, body: .encodable(body)))
    }

    public func put(_ path: String, json: JSONValue = .object([:])) async throws -> Response {
        try await send(Request(.put, path, body: .json(json)))
    }

    public func patch(_ path: String, json: JSONValue = .object([:])) async throws -> Response {
        try await send(Request(.patch, path, body: .json(json)))
    }

    public func delete(_ path: String, json: JSONValue? = nil) async throws -> Response {
        try await send(Request(.delete, path, body: json.map { .json($0) }))
    }

    /// A raw download (PDF, CSV). Goes through the same session handling, so a
    /// download-approval hold surfaces the request-approval flow.
    public func download(_ path: String, query: [String: String?] = [:]) async throws -> (Data, filename: String?, contentType: String?) {
        let r = try await send(Request(.get, path, query: query, headers: ["Accept": "*/*"]))
        let disposition = r.headers["Content-Disposition"] ?? r.headers["content-disposition"]
        let filename = disposition.flatMap { d -> String? in
            guard let range = d.range(of: "filename=") else { return nil }
            return d[range.upperBound...].trimmingCharacters(in: CharacterSet(charactersIn: "\"; "))
        }
        return (r.data, filename, r.headers["Content-Type"] ?? r.headers["content-type"])
    }

    // MARK: Internals

    private func perform(_ request: Request) async throws -> Response {
        var components = URLComponents(url: baseURL.appendingPathComponent(request.path.hasPrefix("/") ? String(request.path.dropFirst()) : request.path), resolvingAgainstBaseURL: false)!
        let items = request.query.compactMap { k, v in v.map { URLQueryItem(name: k, value: $0) } }
        if !items.isEmpty { components.queryItems = items }
        var urlRequest = URLRequest(url: components.url!)
        urlRequest.httpMethod = request.method.rawValue
        urlRequest.setValue(device.id, forHTTPHeaderField: "X-Device-Id")
        urlRequest.setValue("0", forHTTPHeaderField: "X-Device-Touch")
        if request.background { urlRequest.setValue("1", forHTTPHeaderField: "X-Background-Request") }
        if let token = await auth?.bearerToken() { urlRequest.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        for (k, v) in request.headers { urlRequest.setValue(v, forHTTPHeaderField: k) }

        switch request.body {
        case .none:
            break
        case .json(let value):
            urlRequest.setValue("application/json", forHTTPHeaderField: "Content-Type")
            urlRequest.httpBody = try HubJSON.encoder.encode(value)
        case .encodable(let value):
            urlRequest.setValue("application/json", forHTTPHeaderField: "Content-Type")
            urlRequest.httpBody = try HubJSON.encoder.encode(AnyEncodable(value))
        case .multipart(let parts):
            let boundary = "bh-\(UUID().uuidString)"
            urlRequest.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
            urlRequest.httpBody = Self.multipartBody(parts, boundary: boundary)
        }

        let (data, raw) = try await session.data(for: urlRequest)
        guard let http = raw as? HTTPURLResponse else { throw APIError.transport("No HTTP response") }
        var headers: [String: String] = [:]
        for (k, v) in http.allHeaderFields { if let k = k as? String, let v = v as? String { headers[k] = v } }
        return Response(status: http.statusCode, headers: headers, data: data)
    }

    private static func multipartBody(_ parts: [MultipartPart], boundary: String) -> Data {
        var body = Data()
        for p in parts {
            body.append("--\(boundary)\r\n".data(using: .utf8)!)
            var disposition = "Content-Disposition: form-data; name=\"\(p.name)\""
            if let f = p.filename { disposition += "; filename=\"\(f)\"" }
            body.append("\(disposition)\r\n".data(using: .utf8)!)
            if let m = p.mimeType { body.append("Content-Type: \(m)\r\n".data(using: .utf8)!) }
            body.append("\r\n".data(using: .utf8)!)
            body.append(p.data)
            body.append("\r\n".data(using: .utf8)!)
        }
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)
        return body
    }
}

private struct AnyEncodable: Encodable {
    let value: any Encodable
    init(_ value: any Encodable) { self.value = value }
    func encode(to encoder: Encoder) throws { try value.encode(to: encoder) }
}

/// What the session store must provide to the client, and what the client
/// reports back. Implemented by `SessionStore`.
public protocol SessionAuth: AnyObject, Sendable {
    func bearerToken() async -> String?
    func sessionExpired() async
    /// Blocks until the person has unlocked with their terminal PIN; false if they signed out instead.
    func awaitPinUnlock() async -> Bool
    /// Blocks until identity is confirmed (password, TOTP or passkey); false if cancelled.
    func awaitStepUp(_ challenge: StepUpChallenge) async -> Bool
    func downloadHeld(_ payload: JSONValue?) async
}

public struct StepUpChallenge: Sendable, Hashable {
    public enum Method: String, Sendable { case passkey, totp, password }
    public let method: Method
    public let fallback: Method?
    public let reason: String?

    public init(json: JSONValue?) {
        method = Method(rawValue: json?["method"]?.string ?? "password") ?? .password
        fallback = json?["fallback"]?.string.flatMap(Method.init(rawValue:))
        reason = json?["reason"]?.string
    }
}

/// Every failure a screen can show. Normalised like the console's
/// `{status, message, errors, reason}`.
public struct APIError: Error, Sendable, Hashable {
    public let status: Int
    public let message: String
    /// 422 field errors, keyed by field.
    public let errors: [String: [String]]
    /// Machine-readable cause when the API gave one (`reason` or `code`).
    public let reason: String?
    public let retryAfterSeconds: Int?
    public let raw: JSONValue?

    public init(_ response: APIClient.Response) {
        let json = response.json
        status = response.status
        message = json?["message"]?.string ?? HTTPURLResponse.localizedString(forStatusCode: response.status).capitalized
        var e: [String: [String]] = [:]
        if let obj = json?["errors"]?.object {
            for (k, v) in obj { e[k] = v.array?.compactMap(\.string) ?? v.string.map { [$0] } ?? [] }
        }
        errors = e
        reason = json?["reason"]?.string ?? json?["code"]?.string
        retryAfterSeconds = json?["retry_after_seconds"]?.int
        raw = json
    }

    public init(status: Int, message: String, reason: String? = nil) {
        self.status = status
        self.message = message
        self.errors = [:]
        self.reason = reason
        self.retryAfterSeconds = nil
        self.raw = nil
    }

    public static func transport(_ message: String) -> APIError { APIError(status: 0, message: message, reason: "offline") }

    public var isOffline: Bool { status == 0 }
    public var isUnauthenticated: Bool { status == 401 }
    public var isValidation: Bool { status == 422 }
    public var isDeleted: Bool { status == 404 && reason == "deleted" }
    public var firstFieldError: String? { errors.values.first?.first }
}
