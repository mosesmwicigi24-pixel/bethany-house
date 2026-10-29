import SwiftUI
import BethanyKit

/// Database management (super admin + settings.manage_database; every write
/// behind step-up): stats, backups, restore, schedule, storage, clear-by-date.
/// The full wipe is deliberately not offered here.
struct DatabaseView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case backups, schedule, storage, clear }
    @State private var tab: Tab = .backups
    @State private var version = 0
    @State private var restoring: Record?
    @State private var share: URL?

    var body: some View {
        Page("Database", subtitle: "Backups, restore and housekeeping") {
            RemoteJSON("/v1/admin/database/stats") { s, _ in
                TileGrid(minWidth: 160) {
                    StatTile("Size", value: s["database_size_human"]?.string ?? "—")
                    StatTile("pg_dump", value: s["pg_dump_available"]?.bool == true ? "available" : "missing", tone: s["pg_dump_available"]?.bool == true ? .success : .danger)
                }
                if let tables = s["largest_tables"]?.array, !tables.isEmpty {
                    Text("Largest: " + tables.prefix(5).map { "\($0["table"]?.string ?? "") \($0["size_human"]?.string ?? "")" }.joined(separator: " · ")).font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                }
            }
            Tabs(selection: $tab, items: [(.backups, "Backups"), (.schedule, "Schedule"), (.storage, "Storage"), (.clear, "Clear by date")])
            switch tab {
            case .backups: backups
            case .schedule: BackupScheduleView()
            case .storage: StorageSettingsView()
            case .clear: ClearByDateView()
            }
        }
        .sheet(item: $restoring) { b in RestoreSheet(backup: b) { version += 1 } }
        .shareFile($share)
    }

    private var backups: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            HStack {
                Spacer()
                AsyncButton("Back up to local") { let r = try await env.session.client.post("/v1/admin/database/backups", json: J.obj([("disk", .string("local"))])); toastResult(env, r); version += 1 }
                AsyncButton("Back up to S3") { let r = try await env.session.client.post("/v1/admin/database/backups", json: J.obj([("disk", .string("s3"))])); toastResult(env, r); version += 1 }
            }
            RemoteJSON("/v1/admin/database/backups", version: version, emptyTitle: "No backups yet", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { b in
                    SupportListRow(b.string("filename") ?? "", subtitle: "\(b.string("type")?.humanized ?? "") · \(b.string("disk") ?? "") · \(b["size_bytes"]?.int.map { ByteCountFormatter.string(fromByteCount: Int64($0), countStyle: .file) } ?? "—") · by \(b.string("created_by_name") ?? b.string("triggered_by") ?? "") · \(Day.dateTime(b.string("created_at")))\(b.string("error_message").map { " · \($0)" } ?? "")") {
                        HStack {
                            StatusBadge(b.status)
                            if b.status == "success" {
                                AsyncButton { let (d, n, _) = try await env.session.client.download("/v1/admin/database/backups/\(b.id)/download"); presentFile(env, data: d, name: n ?? b.string("filename") ?? "backup.sql", share: $share) } label: { Image(systemName: "arrow.down.doc") }
                                Button("Restore…") { restoring = b }
                            }
                            ConfirmButton("Delete backup", message: "The file is removed from storage.", destructive: true, action: { do { let r = try await env.session.client.delete("/v1/admin/database/backups/\(b.id)"); toastResult(env, r); reload() } catch { env.toasts.error(error) } }) { Image(systemName: "trash") }
                        }
                    }
                }
            }
        }
    }
}

/// POST /database/backups/{id}/restore {confirm_phrase, take_safety_backup}
struct RestoreSheet: View {
    @Environment(AppEnvironment.self) private var env
    let backup: Record
    let done: () -> Void
    @State private var phrase = ""
    @State private var safety = true
    var body: some View {
        EditSheet("Restore \(backup.string("filename") ?? "")", saveLabel: "Restore", canSave: !phrase.isEmpty, destructive: true, save: {
            let r = try await env.session.client.post("/v1/admin/database/backups/\(backup.id)/restore", json: J.obj([("confirm_phrase", .string(phrase)), ("take_safety_backup", .bool(safety))]))
            toastResult(env, r); done()
        }) { e in
            Text("Everything written since this backup is lost. The server checks the confirmation phrase and asks you to confirm it is you.").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
            Field("Confirmation phrase", $phrase, error: e?.errors["confirm_phrase"]?.first)
            ToggleRow("Take a safety backup first", $safety)
        }
    }
}

/// GET/PUT /database/schedule
struct BackupScheduleView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var enabled = false
    @State private var frequency = "daily"
    @State private var runAt = "02:00"
    @State private var dow = "1"
    @State private var dom = "1"
    @State private var retain = "7"
    @State private var disk = "local"
    @State private var last: JSONValue?
    @State private var loaded = false
    var body: some View {
        SectionCard("Scheduled backups") {
            if !loaded { ProgressView() } else {
                ToggleRow("Enabled", $enabled)
                OptionPicker("Frequency", $frequency, options: [("daily", "Daily"), ("weekly", "Weekly"), ("monthly", "Monthly")])
                Field("Run at (HH:mm)", $runAt)
                if frequency == "weekly" { OptionPicker("Day of week", $dow, options: (0...6).map { (String($0), ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"][$0]) }) }
                if frequency == "monthly" { Field("Day of month", $dom) }
                Field("Keep (count)", $retain)
                OptionPicker("Disk", $disk, options: [("local", "Local"), ("s3", "S3")])
                if let l = last, let s = l["last_run_status"]?.string { Text("Last run \(Day.dateTime(l["last_run_at"]?.string)): \(s)\(l["last_run_error"]?.string.map { " — \($0)" } ?? "")").font(Theme.Font.caption).foregroundStyle(s == "success" ? Theme.Palette.success : Theme.Palette.danger) }
                AsyncButton("Save schedule") {
                    let r = try await env.session.client.put("/v1/admin/database/schedule", json: J.obj([("is_enabled", .bool(enabled)), ("frequency", .string(frequency)), ("run_at", .string(runAt)), ("day_of_week", frequency == "weekly" ? J.typed(dow) : .null), ("day_of_month", frequency == "monthly" ? J.typed(dom) : .null), ("retain_count", J.typed(retain)), ("disk", .string(disk))]))
                    toastResult(env, r)
                }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
            }
        }
        .task {
            if let s = try? await env.session.client.json("/v1/admin/database/schedule")["schedule"], !s.isNull {
                enabled = s["is_enabled"]?.bool ?? false; frequency = s["frequency"]?.string ?? "daily"; runAt = String((s["run_at"]?.string ?? "02:00").prefix(5))
                dow = s["day_of_week"]?.string ?? "1"; dom = s["day_of_month"]?.string ?? "1"; retain = s["retain_count"]?.string ?? "7"; disk = s["disk"]?.string ?? "local"; last = s
            }
            loaded = true
        }
    }
}

/// GET/PUT /database/storage-settings; POST /storage-settings/test {disk}
struct StorageSettingsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var f: [String: String] = [:]
    @State private var loaded = false
    static let keys: [(String, String)] = [("backup_local_retain_count", "Local copies to keep"), ("backup_s3_bucket", "S3 bucket"), ("backup_s3_region", "S3 region"), ("backup_s3_endpoint", "S3 endpoint"), ("backup_s3_key", "S3 key"), ("backup_s3_secret", "S3 secret (masked)"), ("backup_s3_prefix", "S3 prefix"), ("backup_s3_use_path_style", "Path-style URLs (1/0)"), ("backup_notify_email", "Notify email")]
    var body: some View {
        SectionCard("Backup storage") {
            if !loaded { ProgressView() } else {
                OptionPicker("Destination", Binding(get: { f["backup_storage_disk"] ?? "local" }, set: { f["backup_storage_disk"] = $0 }), options: [("local", "Local disk"), ("s3", "S3-compatible")])
                ForEach(Self.keys, id: \.0) { k in Field(k.1, Binding(get: { f[k.0] ?? "" }, set: { f[k.0] = $0 })) }
                HStack {
                    AsyncButton("Save") { let r = try await env.session.client.put("/v1/admin/database/storage-settings", json: J.obj((Self.keys.map(\.0) + ["backup_storage_disk"]).map { ($0, J.s(f[$0]) as JSONValue?) })); toastResult(env, r) }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    AsyncButton("Test connection") { let r = try await env.session.client.post("/v1/admin/database/storage-settings/test", json: J.obj([("disk", .string(f["backup_storage_disk"] ?? "local"))])); env.toasts.show(r.json?.message ?? "Tested", tone: r.json?["ok"]?.bool == false ? .danger : .success) }
                }
            }
        }
        .task {
            if let s = try? await env.session.client.json("/v1/admin/database/storage-settings")["settings"]?.object { for (k, v) in s { f[k] = v.string ?? "" } }
            loaded = true
        }
    }
}

/// GET /database/clearable-tables; POST /clear-preview {tables, before_date}; POST /clear {tables, before_date, confirm: true, skip_auto_backup}
struct ClearByDateView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var tables: [Record] = []
    @State private var chosen: Set<String> = []
    @State private var before = Day.add(Day.today(), days: -365)
    @State private var preview: JSONValue?
    @State private var skipBackup = false
    var body: some View {
        SectionCard("Clear transactions before a date") {
            Text("Removes ledger and business rows older than the date from the chosen tables, after a safety backup. The server asks you to confirm it is you.").font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
            DayField("Before", $before)
            ForEach(tables) { t in
                let key = t.string("key") ?? ""
                Toggle(isOn: Binding(get: { chosen.contains(key) }, set: { if $0 { chosen.insert(key) } else { chosen.remove(key) } })) {
                    Text("\(t.string("label") ?? key) · \(t.string("group") ?? "") · \(t.int("row_count") ?? 0) rows").font(Theme.Font.secondary)
                }.toggleStyle(.checkbox)
            }
            HStack {
                AsyncButton("Preview") { preview = try await env.session.client.post("/v1/admin/database/clear-preview", json: J.obj([("tables", J.strs(Array(chosen))), ("before_date", .string(before))])).json }.disabled(chosen.isEmpty)
                if let p = preview {
                    Text("\(p["total_rows"]?.int ?? 0) rows would go: " + (p["preview"]?.object ?? [:]).map { "\($0.key) \($0.value.int ?? 0)" }.sorted().joined(separator: ", ")).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                }
            }
            ToggleRow("Skip the automatic safety backup", $skipBackup)
            ConfirmButton("Clear now", message: "Rows older than \(before) are deleted from \(chosen.count) table group(s). This cannot be undone without the backup.", destructive: true, action: {
                do {
                    let r = try await env.session.client.post("/v1/admin/database/clear", json: J.obj([("tables", J.strs(Array(chosen))), ("before_date", .string(before)), ("confirm", .bool(true)), ("skip_auto_backup", .bool(skipBackup))]))
                    env.toasts.show("\(r.json?.message ?? "Cleared") — \(r.json?["total_deleted"]?.int ?? 0) rows"); preview = nil
                } catch { env.toasts.error(error) }
            }) { Text("Clear now").foregroundStyle(Theme.Palette.danger) }.disabled(preview == nil)
        }
        .task { tables = (try? await env.session.client.json("/v1/admin/database/clearable-tables"))?["data"]?.records ?? [] }
    }
}

// MARK: - Downloads

/// Download approvals (owner + delegates; the server decides): the queue
/// (approve with step-up, deny with a note), the ledger, approvers.
struct DownloadApprovalsView: View {
    @Environment(AppEnvironment.self) private var env
    enum Tab: Hashable { case waiting, all, approvers }
    @State private var tab: Tab = .waiting
    @State private var version = 0
    @State private var category = ""
    @State private var denying: Record?
    @State private var share: URL?

    var body: some View {
        Page("Download approvals", subtitle: "Every file a staff member takes passes the gate") {
            RemoteJSON("/v1/admin/downloads/capabilities", version: version) { caps, _ in
                if caps["can_approve"]?.bool != true {
                    EmptyState("You are not a download approver", message: "The owner and the managers he delegates to decide these.", symbol: "lock")
                } else {
                    HStack { Pill(caps["enforcing"]?.bool == true ? "gate enforcing" : "recording only", caps["enforcing"]?.bool == true ? .success : .warning); Pill("\(caps["pending"]?.int ?? 0) waiting", .amber) }
                    Tabs(selection: $tab, items: [(.waiting, "Waiting"), (.all, "Ledger")] + (caps["is_owner"]?.bool == true ? [(.approvers, "Approvers")] : []))
                    switch tab {
                    case .waiting: queue
                    case .all: ledger
                    case .approvers: ApproversPanel()
                    }
                }
            }
        }
        .sheet(item: $denying) { r in DenySheet(request: r) { version += 1 } }
        .shareFile($share)
    }

    private var queue: some View {
        RemoteJSON("/v1/admin/downloads/requests", query: ["status": "pending"], version: version, emptyTitle: "Nothing waiting", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
            ForEach(j["data"]?.records ?? []) { r in
                SectionCard {
                    HStack(alignment: .top) {
                        VStack(alignment: .leading, spacing: 3) {
                            Text(r.string("label") ?? r.string("path") ?? "").font(Theme.Font.body.weight(.semibold))
                            Text("\(requester(r)) · \(r.string("category")?.humanized ?? "") · \(r.string("method") ?? "") \(r.string("path") ?? "")").font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                            if let reason = r.string("reason") { Text("“\(reason)”").font(Theme.Font.secondary) }
                            if let p = r["payload"], !p.isNull { Text(prettyJSON(p)).font(Theme.Font.mono).foregroundStyle(Theme.textTertiary).lineLimit(6) }
                        }
                        Spacer()
                        VStack(alignment: .trailing) { StatusBadge(r.status); DateText(r.date("created_at"), style: .relative) }
                    }
                    HStack {
                        Spacer()
                        Button("Decline") { denying = r }
                        ConfirmButton("Approve download", message: "The person gets a single-use link to exactly this file. You will be asked to confirm it is you.", action: {
                            do { let x = try await env.session.client.post("/v1/admin/downloads/requests/\(r.string("uuid") ?? "")/approve", json: J.obj([])); toastResult(env, x); reload() } catch { env.toasts.error(error) }
                        }) { Text("Approve") }.buttonStyle(.borderedProminent).tint(Theme.Palette.brand)
                    }
                }
            }
        }
    }

    private var ledger: some View {
        VStack(alignment: .leading, spacing: Theme.Space.m) {
            OptionPicker("Kind", $category, options: [("", "Every kind"), ("gated", "Needs approval"), ("never_attach", "Database backups"), ("exempt", "Invoices, quotations, receipts"), ("bulk_contacts", "Bulk contacts")])
            RemoteJSON("/v1/admin/downloads/all", query: ["category": category.isEmpty ? nil : category], version: version, emptyTitle: "No downloads recorded", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, _ in
                ForEach(j["data"]?.records ?? []) { r in
                    SupportListRow(r.string("label") ?? "", subtitle: "\(requester(r)) · \(r.string("category")?.humanized ?? "") · \(r.string("downloaded_at").map { "taken \(Day.dateTime($0))" } ?? "not taken")\(r["decider"].flatMap { d in d.isNull ? nil : " · \(r.status == "denied" ? "declined" : "approved") by \(d["first_name"]?.string ?? "") \(d["last_name"]?.string ?? "")" } ?? "")\(r["file_bytes"]?.int.map { " · \(ByteCountFormatter.string(fromByteCount: Int64($0), countStyle: .file))" } ?? "")") {
                        HStack {
                            StatusBadge(r.status)
                            if r.string("archive_path") != nil {
                                AsyncButton { let (d, n, _) = try await env.session.client.download("/v1/admin/downloads/\(r.string("uuid") ?? "")/archive"); presentFile(env, data: d, name: n ?? r.string("file_name") ?? "download", share: $share) } label: { Image(systemName: "archivebox") }
                            }
                        }
                    }
                }
                Text("Page \(j["current_page"]?.int ?? 1) of \(j["last_page"]?.int ?? 1) · \(j["total"]?.int ?? 0)").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
    }

    private func requester(_ r: Record) -> String {
        guard let u = r["user"], !u.isNull else { return "user #\(r.int("user_id") ?? 0)" }
        return "\(u["first_name"]?.string ?? "") \(u["last_name"]?.string ?? "")"
    }
}

/// POST /downloads/requests/{uuid}/deny {note}
struct DenySheet: View {
    @Environment(AppEnvironment.self) private var env
    let request: Record
    let done: () -> Void
    @State private var note = ""
    var body: some View {
        EditSheet("Decline download", saveLabel: "Decline", canSave: !note.isEmpty, destructive: true, save: {
            let r = try await env.session.client.post("/v1/admin/downloads/requests/\(request.string("uuid") ?? "")/deny", json: J.obj([("note", .string(note))]))
            toastResult(env, r); done()
        }) { e in
            Text(request.string("label") ?? "").font(Theme.Font.secondary)
            Field("Why (the person sees this)", $note, error: e?.errors["note"]?.first, multiline: true)
        }
    }
}

/// GET /downloads/approvers; POST {user_id} (step-up); DELETE /approvers/{userId} (step-up)
struct ApproversPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var adding: Int?
    var body: some View {
        RemoteJSON("/v1/admin/downloads/approvers", version: version) { j, reload in
            SectionCard("Owner") { Text(j["owner"].flatMap { o in o.isNull ? nil : "\(o["first_name"]?.string ?? "") \(o["last_name"]?.string ?? "") · \(o["email"]?.string ?? "")" } ?? "—").font(Theme.Font.secondary) }
            SectionCard("Delegates") {
                ForEach(j["delegates"]?.records ?? []) { d in
                    HStack {
                        Text("\(d["user"]?["first_name"]?.string ?? "") \(d["user"]?["last_name"]?.string ?? "") · \(d["user"]?["email"]?.string ?? "")").font(Theme.Font.secondary)
                        Spacer()
                        AsyncButton(role: .destructive) { let r = try await env.session.client.delete("/v1/admin/downloads/approvers/\(d["user"]?["id"]?.int ?? 0)"); toastResult(env, r); reload() } label: { Image(systemName: "minus.circle") }
                    }
                }
                HStack(alignment: .bottom) {
                    StaffPicker("Add a delegate", $adding)
                    AsyncButton("Add (step-up)") { let r = try await env.session.client.post("/v1/admin/downloads/approvers", json: J.obj([("user_id", J.i(adding))])); toastResult(env, r); adding = nil; reload() }.disabled(adding == nil)
                }
            }
        }
    }
}

/// My downloads (GET /downloads/mine): cancel a waiting request; take an approved one
/// (POST /requests/{uuid}/token, then replay the approved request with the token header).
struct MyDownloadsView: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var share: URL?
    var body: some View {
        Page("My downloads", subtitle: "Files you asked for and what happened to them") {
            RemoteJSON("/v1/admin/downloads/mine", version: version, emptyTitle: "You have not asked for any downloads", isEmpty: { ($0["data"]?.array ?? []).isEmpty }) { j, reload in
                ForEach(j["data"]?.records ?? []) { r in
                    SupportListRow(r.string("label") ?? "", subtitle: "\(Day.dateTime(r.string("created_at"))) · \(r.string("category")?.humanized ?? "")\(r.string("decision_note").map { " · \($0)" } ?? "")\(r.string("downloaded_at").map { " · taken \(Day.dateTime($0))" } ?? "")") {
                        HStack {
                            StatusBadge(r.status)
                            if r.status == "pending" { AsyncButton("Cancel") { let x = try await env.session.client.post("/v1/admin/downloads/requests/\(r.string("uuid") ?? "")/cancel"); toastResult(env, x); reload() } }
                            if r.status == "approved" { AsyncButton("Download") { try await take(r) } .buttonStyle(.borderedProminent).tint(Theme.Palette.brand) }
                        }
                    }
                }
            }
        }
        .shareFile($share)
    }

    private func take(_ r: Record) async throws {
        let t = try await env.session.client.post("/v1/admin/downloads/requests/\(r.string("uuid") ?? "")/token").json ?? .null
        guard let token = t["token"]?.string, let header = t["header"]?.string, var path = t["path"]?.string else { throw APIError(status: 0, message: "No download link was issued.") }
        if path.hasPrefix("/api") { path = String(path.dropFirst(4)) }
        let method = (t["method"]?.string ?? "GET").uppercased()
        var query: [String: String?] = [:]
        for (k, v) in t["payload"]?.object ?? [:] { query[k] = v.string }
        let request: APIClient.Request = method == "GET"
            ? .init(.get, path, query: query, headers: [header: token, "Accept": "*/*"])
            : .init(.post, path, body: .json(t["payload"] ?? .object([:])), headers: [header: token, "Accept": "*/*"])
        let res = try await env.session.client.send(request)
        let disposition = res.headers["Content-Disposition"] ?? res.headers["content-disposition"] ?? ""
        let name = disposition.range(of: "filename=").map { String(disposition[$0.upperBound...]).trimmingCharacters(in: CharacterSet(charactersIn: "\"; ")) } ?? (r.string("file_name") ?? r.string("export_id") ?? "download")
        presentFile(env, data: res.data, name: name, share: $share)
        version += 1
    }
}
