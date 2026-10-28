package ke.co.bethanyhouse.hub.ui.features.me

import ke.co.bethanyhouse.hub.core.Route
import ke.co.bethanyhouse.hub.ui.shell.FeatureRegistry

/**
 * Me: the staff member's own module (console `/me/…`, `/updates`, `/forms`,
 * `/handbook`, `/courses`, `/me/sign`, `/todos`, `/colleagues`). Screens live
 * in the Me*.kt files of this package; this one registers the routes; MeSupport.kt holds
 * the pieces they share (formatting, avatars, the signature pad, photo picking).
 */
object MeFeature {
    fun register() {
        FeatureRegistry.register(Route.Me) { MeTodayScreen() }
        FeatureRegistry.register(Route.Clock) { ClockScreen() }
        FeatureRegistry.register(Route.MyShifts) { MyShiftsScreen() }
        FeatureRegistry.register(Route.MyLeave) { MyLeaveScreen() }
        FeatureRegistry.register(Route.MyTimesheet) { MyTimesheetScreen() }
        FeatureRegistry.register(Route.MyPayslips) { MyPayslipsScreen() }
        FeatureRegistry.register(Route.MyAdvances) { MyAdvancesScreen() }
        FeatureRegistry.registerType<Route.Updates> { r -> val id = r.id; if (id != null) PostDetailScreen(id) else UpdatesScreen() }
        FeatureRegistry.register(Route.Forms) { FormsScreen() }
        FeatureRegistry.registerType<Route.Handbook> { r -> val id = r.id; if (id != null) ArticleScreen(id) else HandbookScreen() }
        FeatureRegistry.registerType<Route.Courses> { r -> val id = r.id; if (id != null) CourseScreen(id) else CoursesScreen() }
        FeatureRegistry.registerType<Route.Sign> { r -> val id = r.id; if (id != null) SignDetailScreen(id) else SignScreen() }
        FeatureRegistry.register(Route.Todos) { TodosScreen() }
        FeatureRegistry.register(Route.Colleagues) { ColleaguesScreen() }
    }
}
