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
        ke.co.bethanyhouse.hub.ui.features.pos.PosFeature.register()
        ke.co.bethanyhouse.hub.ui.features.sales.SalesFeature.register()
        ke.co.bethanyhouse.hub.ui.features.procurement.ProcurementFeature.register()
        ke.co.bethanyhouse.hub.ui.features.procurement.CatalogFeature.register()
        ke.co.bethanyhouse.hub.ui.features.people.PeopleFeature.register()
        ke.co.bethanyhouse.hub.ui.features.people.CommsFeature.register()
        ke.co.bethanyhouse.hub.ui.features.setup.SetupFeature.register()
        ke.co.bethanyhouse.hub.ui.features.setup.MarketingFeature.register()
    }
}
