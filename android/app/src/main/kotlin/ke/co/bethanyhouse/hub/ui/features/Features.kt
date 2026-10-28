package ke.co.bethanyhouse.hub.ui.features

/**
 * Every module registers its native screens here, in sidebar order. A
 * route no module claims falls back to the console bridge (RouteScreen).
 */
object Features {
    fun registerAll() {
        WorkspaceFeature.register()
        ProfileFeature.register()
        ke.co.bethanyhouse.hub.ui.features.reports.ReportsFeature.register()
        ke.co.bethanyhouse.hub.ui.features.expenses.ExpensesFeature.register()
        ke.co.bethanyhouse.hub.ui.features.expenses.FinanceFeature.register()
        ke.co.bethanyhouse.hub.ui.features.production.ProductionFeature.register()
    }
}
