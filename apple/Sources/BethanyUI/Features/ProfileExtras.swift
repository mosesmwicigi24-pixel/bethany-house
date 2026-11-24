import SwiftUI
import BethanyKit

// The profile page's console parts beyond sign-in (ProfilePage.tsx tabs
// Profile, Preferences, My employment, My Activity; DeviceList.tsx).

// MARK: - Details & preferences — PUT profile {first_name, last_name, phone, preferred_language, preferred_currency}

struct EditProfileSheet: View {
    @Environment(AppEnvironment.self) private var env
    @State private var first = ""
    @State private var last = ""
    @State private var phone = ""
    @State private var language = "en"
    @State private var currency = "KES"
    @State private var email = ""
    @State private var languages: [Record] = []
    @State private var currencies: [Record] = []
    @State private var loadError: String?
    @State private var loaded = false

    var body: some View {
        EditSheet("Details & preferences", canSave: loaded && !first.trimmingCharacters(in: .whitespaces).isEmpty && !last.trimmingCharacters(in: .whitespaces).isEmpty, save: {
            let r = try await env.session.client.put("/v1/admin/profile", json: .object([
                "first_name": .string(first.trimmingCharacters(in: .whitespaces)), "last_name": .string(last.trimmingCharacters(in: .whitespaces)),
                "phone": .string(phone.trimmingCharacters(in: .whitespaces)), "preferred_language": .string(language), "preferred_currency": .string(currency),
            ]))
            toastResult(env, r, fallback: "Profile updated")
            await env.session.refreshMe()
        }) { e in
            if let loadError {
                Text(loadError).font(Theme.Font.secondary).foregroundStyle(Theme.Palette.danger)
                Button("Try again") { Task { await loadProfile() } }
            } else if !loaded {
                ProgressView("Loading your details…")
            }
            if loaded {
            Field("First name", $first, error: e?.errors["first_name"]?.first)
            Field("Last name", $last, error: e?.errors["last_name"]?.first)
            DetailRow("Email", email)
            Text("Contact admin to change your email address.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            Field("Phone number", $phone, placeholder: "+254 700 000 000", error: e?.errors["phone"]?.first)
            Picker("Preferred language", selection: $language) {
                if languages.isEmpty { Text(language.uppercased()).tag(language) }
                ForEach(languages) { l in Text("\(l.string("name") ?? "") (\(l.string("native_name") ?? l.string("code") ?? ""))").tag(l.string("code") ?? "") }
            }
            Picker("Preferred currency", selection: $currency) {
                if currencies.isEmpty { Text(currency).tag(currency) }
                ForEach(currencies) { c in Text("\(c.string("code") ?? "") - \(c.string("name") ?? "")").tag(c.string("code") ?? "") }
            }
            Text("Preferences apply to your personal view of the Hub.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
        }
        .task { await loadProfile() }
    }

    private func loadProfile() async {
        loadError = nil
        do {
            let c = env.session.client
            guard let u = try await c.get("/v1/admin/profile").json?["user"] else {
                loadError = "Your details could not be loaded. Please try again."; return
            }
            do {
                first = u["first_name"]?.string ?? ""; last = u["last_name"]?.string ?? ""; phone = u["phone"]?.string ?? ""
                email = u["email"]?.string ?? ""
                language = u["preferred_language"]?.string ?? "en"; currency = u["preferred_currency"]?.string ?? "KES"
            }
            languages = (try? await c.get("/v1/admin/languages"))?.json?.records ?? []
            currencies = (try? await c.get("/v1/admin/currencies-management"))?.json?.records ?? []
            loaded = true
        } catch { loadError = "Your details could not be loaded. Please try again." }
    }
}

// MARK: - Rename a device — PUT profile/devices/{id} {name|null}

struct RenameDeviceSheet: View {
    @Environment(AppEnvironment.self) private var env
    let device: ProfileDevice
    let done: () -> Void
    @State private var draft: String

    init(device: ProfileDevice, done: @escaping () -> Void) {
        self.device = device; self.done = done
        _draft = State(initialValue: device.name ?? "")
    }

    var body: some View {
        EditSheet(device.name == nil ? "Name this device" : "Rename device", canSave: ProfileDevice.isValidName(draft), save: {
            let name: JSONValue = ProfileDevice.nameValue(draft).map { .string($0) } ?? .null
            let r = try await env.session.client.put("/v1/admin/profile/devices/\(device.id)", json: .object(["name": name]))
            toastResult(env, r, fallback: "Device renamed"); done()
        }) { e in
            DetailRow("Device", device.label)
            Field("Name (2–60 characters; empty clears it)", $draft, error: e?.errors["name"]?.first)
        }
    }
}

// MARK: - My employment — GET/PUT me/file, GET me/file/sensitive, me/file/documents/{id}

struct MyEmploymentPanel: View {
    @Environment(AppEnvironment.self) private var env
    @State private var version = 0
    @State private var revealed: JSONValue?
    @State private var editing = false

    var body: some View {
        RemoteJSON("/v1/admin/me/file", version: version) { j, _ in
            let me = MyEmployment(json: j)
            SectionCard("Employment") {
                if let value = me.jobTitle, !value.isEmpty { DetailRow("Job title", value) }
                if let value = me.department, !value.isEmpty { DetailRow("Department", value) }
                if let value = me.employmentType, !value.isEmpty { DetailRow("Type", value.humanized) }
                if let value = me.hireDate { DetailRow("Since", PayrollText.date(value)) }
                if let value = me.reportsTo, !value.isEmpty { DetailRow("Reports to", value) }
                if let value = me.payMethod, !value.isEmpty { DetailRow("Pay method", me.payMethodText) }
                Text("Something wrong here? Ask whoever keeps the employee files; only they can change it.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
            }
            SectionCard("Mine to keep") {
                if let dob = me.dateOfBirth, !dob.isEmpty { DetailRow("Date of birth", PayrollText.date(dob)) }
                if let contact = [me.emergencyName, me.emergencyRelationship, me.emergencyPhone].compactMap({ $0?.nilIfEmpty }).joined(separator: " · ").nilIfEmpty {
                    DetailRow("Emergency contact", contact)
                } else { Text("Add an emergency contact so your team knows who to reach.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                Button("Edit personal details") { editing = true }.buttonStyle(.bordered).tint(Theme.Palette.brand)
            }
            if me.masked.contains(where: { !($0.value ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) {
                SectionCard("Identifiers") {
                    ForEach(Array(me.masked.enumerated()), id: \.offset) { i, m in
                        if let value = m.value, !value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                            let key = MyEmployment.identifierLabels[i].key
                            DetailRow(m.label, revealed?[key]?.string ?? value)
                        }
                    }
                    if revealed == nil {
                        AsyncButton(action: { revealed = try await env.session.client.get("/v1/admin/me/file/sensitive").json?["sensitive"] }) { Text("Show in full") }
                            .font(Theme.Font.secondary)
                        Text("Shown masked until you confirm it is you.").font(Theme.Font.caption).foregroundStyle(Theme.textTertiary)
                    }
                }
            }
            SectionCard("My documents") {
                if me.documents.isEmpty { Text("No documents on file.").font(Theme.Font.secondary).foregroundStyle(Theme.textSecondary) }
                ForEach(me.documents, id: \.id) { d in
                    HStack {
                        Text(d.name).font(Theme.Font.body)
                        Spacer()
                        SignedFileButton(label: "Open", issuer: "/v1/admin/me/file/documents/\(d.id)", suggestedName: d.name)
                    }
                }
            }
            .sheet(isPresented: $editing) { MyFileEditSheet(me: me) { version += 1 } }
        }
    }
}

/// PUT me/file {date_of_birth?, emergency_contact_name/phone/relationship}
struct MyFileEditSheet: View {
    @Environment(AppEnvironment.self) private var env
    let me: MyEmployment
    let done: () -> Void
    @State private var dob: String
    @State private var name: String
    @State private var phone: String
    @State private var relationship: String

    init(me: MyEmployment, done: @escaping () -> Void) {
        self.me = me; self.done = done
        _dob = State(initialValue: me.dateOfBirth ?? ""); _name = State(initialValue: me.emergencyName ?? "")
        _phone = State(initialValue: me.emergencyPhone ?? ""); _relationship = State(initialValue: me.emergencyRelationship ?? "")
    }

    var body: some View {
        EditSheet("My details", save: {
            let r = try await env.session.client.put("/v1/admin/me/file", json: J.obj([
                ("date_of_birth", dob.isEmpty ? .null : .string(dob)),
                ("emergency_contact_name", J.sOpt(name.trimmingCharacters(in: .whitespaces)) ?? .null),
                ("emergency_contact_phone", J.sOpt(phone.trimmingCharacters(in: .whitespaces)) ?? .null),
                ("emergency_contact_relationship", J.sOpt(relationship.trimmingCharacters(in: .whitespaces)) ?? .null),
            ]))
            toastResult(env, r); done()
        }) { e in
            DayField("Date of birth", $dob)
            Field("Emergency contact name", $name, error: e?.errors["emergency_contact_name"]?.first)
            Field("Their phone", $phone, error: e?.errors["emergency_contact_phone"]?.first)
            Field("Relationship", $relationship)
        }
    }
}

// MARK: - My activity — GET profile/activity?per_page=20

struct MyActivityPanel: View {
    var body: some View {
        RemoteJSON("/v1/admin/profile/activity", query: ["per_page": "20"], emptyTitle: "No activity recorded yet.", isEmpty: { ProfileActivity.rows($0).isEmpty }) { j, _ in
            SectionCard("Last 20 actions performed by your account") {
                ForEach(ProfileActivity.rows(j)) { a in
                    HStack {
                        Text(a.text).font(Theme.Font.body)
                        Spacer()
                        if let date = a.createdAt {
                            Text(date.formatted(date: .abbreviated, time: .shortened)).font(Theme.Font.caption).foregroundStyle(Theme.textSecondary)
                        }
                    }
                    Divider()
                }
            }
        }
    }
}

private extension String {
    var nilIfEmpty: String? { isEmpty ? nil : self }
}
