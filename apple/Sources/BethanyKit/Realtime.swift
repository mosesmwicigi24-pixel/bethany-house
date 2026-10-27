import Foundation

/// Reverb (Pusher protocol) over URLSessionWebSocketTask: the realtime side
/// of messages, comments and notifications (react-admin/src/lib/echo.ts).
///
/// Channels: `user.{id}` (`.notification.pushed`), `thread.{model}.{id}`
/// (`.comment.posted`), `channel.{id}` (`.message.sent`, `.reaction.updated`,
/// `.read.updated`), `presence.channel.{id}` (typing). Private and presence
/// channels are signed by `POST /api/broadcasting/auth` with the bearer token.
public actor RealtimeClient {
    public struct Configuration: Sendable {
        public var appKey: String
        public var host: String
        public var port: Int
        public var scheme: String   // "wss" or "ws"
        public init(appKey: String, host: String, port: Int = 443, scheme: String = "wss") {
            self.appKey = appKey; self.host = host; self.port = port; self.scheme = scheme
        }
    }

    public struct Event: Sendable {
        public let channel: String?
        public let name: String
        public let payload: JSONValue?
    }

    private let config: Configuration
    private let client: APIClient
    private var task: URLSessionWebSocketTask?
    private var socketId: String?
    private var subscriptions: [String: [UUID: @Sendable (Event) -> Void]] = [:]
    private var reconnectDelay: TimeInterval = 1
    private var running = false

    public init(configuration: Configuration, client: APIClient) {
        self.config = configuration
        self.client = client
    }

    public func connect() {
        guard !running else { return }
        running = true
        Task { await self.loop() }
    }

    public func disconnect() {
        running = false
        task?.cancel(with: .goingAway, reason: nil)
        task = nil
        socketId = nil
    }

    /// Subscribes (private channels get `private-` on the wire). Returns a token to unsubscribe.
    @discardableResult
    public func subscribe(_ channel: String, presence: Bool = false, handler: @escaping @Sendable (Event) -> Void) async -> UUID {
        let wire = presence ? "presence-\(channel)" : (channel.hasPrefix("private-") ? channel : "private-\(channel)")
        let id = UUID()
        let fresh = subscriptions[wire] == nil
        subscriptions[wire, default: [:]][id] = handler
        if fresh { await sendSubscribe(wire) }
        return id
    }

    public func unsubscribe(_ channel: String, token: UUID) async {
        for key in subscriptions.keys where key.hasSuffix(channel) {
            subscriptions[key]?[token] = nil
            if subscriptions[key]?.isEmpty == true {
                subscriptions[key] = nil
                await send(["event": "pusher:unsubscribe", "data": .object(["channel": .string(key)])])
            }
        }
    }

    /// Client event on a presence channel (typing indicators).
    public func whisper(_ channel: String, event: String, data: JSONValue) async {
        await send(["event": .string("client-\(event)"), "channel": .string("presence-\(channel)"), "data": data])
    }

    // MARK: Internals

    private func loop() async {
        while running {
            var comps = URLComponents()
            comps.scheme = config.scheme
            comps.host = config.host
            comps.port = config.port
            comps.path = "/app/\(config.appKey)"
            comps.queryItems = [URLQueryItem(name: "protocol", value: "7"), URLQueryItem(name: "client", value: "bethany-hub-swift"), URLQueryItem(name: "version", value: "1.0")]
            guard let url = comps.url else { return }
            let t = URLSession.shared.webSocketTask(with: url)
            task = t
            t.resume()
            do {
                while running {
                    let message = try await t.receive()
                    let text: String
                    switch message {
                    case .string(let s): text = s
                    case .data(let d): text = String(decoding: d, as: UTF8.self)
                    @unknown default: continue
                    }
                    await handle(text)
                }
            } catch {
                socketId = nil
                if running {
                    try? await Task.sleep(for: .seconds(reconnectDelay))
                    reconnectDelay = min(reconnectDelay * 2, 30)
                }
            }
        }
    }

    private func handle(_ text: String) async {
        guard let data = text.data(using: .utf8), let msg = try? HubJSON.decoder.decode(JSONValue.self, from: data) else { return }
        let name = msg["event"]?.string ?? ""
        let channel = msg["channel"]?.string
        var payload = msg["data"]
        if case .string(let s)? = payload, let d = s.data(using: .utf8), let inner = try? HubJSON.decoder.decode(JSONValue.self, from: d) {
            payload = inner
        }
        switch name {
        case "pusher:connection_established":
            socketId = payload?["socket_id"]?.string
            reconnectDelay = 1
            for wire in subscriptions.keys { await sendSubscribe(wire) }
        case "pusher:ping":
            await send(["event": "pusher:pong", "data": .object([:])])
        case "pusher:error", "pusher_internal:subscription_succeeded", "pusher_internal:member_added", "pusher_internal:member_removed":
            if let channel { dispatch(Event(channel: channel, name: name, payload: payload)) }
        default:
            dispatch(Event(channel: channel, name: name, payload: payload))
        }
    }

    private func dispatch(_ event: Event) {
        guard let channel = event.channel, let handlers = subscriptions[channel] else { return }
        for h in handlers.values { h(event) }
    }

    private func sendSubscribe(_ wire: String) async {
        guard let socketId else { return }
        var data: [String: JSONValue] = ["channel": .string(wire)]
        do {
            let r = try await client.send(.init(.post, "/broadcasting/auth", body: .json(.object([
                "socket_id": .string(socketId), "channel_name": .string(wire),
            ]))))
            if let auth = r.json?["auth"]?.string { data["auth"] = .string(auth) }
            if let cd = r.json?["channel_data"]?.string { data["channel_data"] = .string(cd) }
        } catch {
            return
        }
        await send(["event": "pusher:subscribe", "data": .object(data)])
    }

    private func send(_ object: [String: JSONValue]) async {
        guard let task, let data = try? HubJSON.encoder.encode(JSONValue.object(object)), let text = String(data: data, encoding: .utf8) else { return }
        try? await task.send(.string(text))
    }
}

extension JSONValue: ExpressibleByDictionaryLiteral, ExpressibleByStringLiteral {
    public init(dictionaryLiteral elements: (String, JSONValue)...) {
        self = .object(Dictionary(uniqueKeysWithValues: elements))
    }
    public init(stringLiteral value: String) { self = .string(value) }
}
