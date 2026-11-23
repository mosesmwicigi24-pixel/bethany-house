import SwiftUI
import BethanyKit

/// Sign out, but first say when changes made offline have not reached the Hub.
///
/// They wait in this person's own store on the device (`OfflineStore(userId:)`)
/// and are sent the next time they sign in here, never under someone else's
/// sign-in; nobody should leave thinking their work is in when it is not.
/// The same rule as Android's sidebar sign-out.
extension AppEnvironment {
    /// Changes the signed-in person made offline that have not been sent.
    /// -1 when the saved queue cannot be read: unknown is never "none".
    func unsentChanges() async -> Int {
        guard let offline else { return 0 }
        do { return try await offline.pendingChecked().count } catch { return -1 }
    }

    func signOutNow() async {
        await session.signOut()
        await sessionDidChange()
    }

    /// Signs out when nothing is waiting; otherwise returns how many changes
    /// are, so the caller can ask first (`SignOutGuard`).
    func requestSignOut() async -> Int {
        let waiting = await unsentChanges()
        if waiting == 0 { await signOutNow() }
        return waiting
    }
}

/// The question asked when `requestSignOut()` found changes still waiting.
struct SignOutGuard: ViewModifier {
    @Environment(AppEnvironment.self) private var env
    @Binding var unsent: Int

    func body(content: Content) -> some View {
        content.alert(unsent < 0 ? "Saved changes can't be read" : "Changes not sent yet", isPresented: Binding(get: { unsent != 0 }, set: { if !$0 { unsent = 0 } })) {
            Button("Sign out anyway", role: .destructive) { Task { await env.signOutNow() } }
            Button("Stay signed in", role: .cancel) {}
        } message: {
            Text(Self.message(unsent))
        }
    }

    static func message(_ n: Int) -> String {
        if n < 0 {
            return "Changes saved on this device could not be read, so nobody can tell whether any are still waiting to reach the Hub. "
                + "They stay on this device if you sign out. Ask a manager for help before signing out."
        }
        return (n == 1 ? "1 change you made offline has" : "\(n) changes you made offline have")
            + " not reached the Hub. They stay on this device and are sent the next time you sign in here. "
            + "To send them now, wait for a connection before signing out."
    }
}
