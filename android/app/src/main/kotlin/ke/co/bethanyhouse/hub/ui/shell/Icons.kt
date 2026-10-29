package ke.co.bethanyhouse.hub.ui.shell

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Assignment
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.automirrored.filled.ListAlt
import androidx.compose.material.icons.automirrored.filled.MenuBook
import androidx.compose.material.icons.automirrored.filled.Rule
import androidx.compose.material.icons.automirrored.filled.ShowChart
import androidx.compose.material.icons.automirrored.filled.Undo
import androidx.compose.material.icons.filled.*
import androidx.compose.ui.graphics.vector.ImageVector

/** Material icon by the name the navigation catalogue carries (`NavItem.icon`). */
fun iconFor(name: String): ImageVector = when (name) {
    "AccountBalance" -> Icons.Default.AccountBalance
    "AccountBalanceWallet" -> Icons.Default.AccountBalanceWallet
    "AccountCircle" -> Icons.Default.AccountCircle
    "Apartment" -> Icons.Default.Apartment
    "Assignment" -> Icons.AutoMirrored.Filled.Assignment
    "BarChart" -> Icons.Default.BarChart
    "BeachAccess" -> Icons.Default.BeachAccess
    "Bolt" -> Icons.Default.Bolt
    "Business" -> Icons.Default.Business
    "CalendarMonth" -> Icons.Default.CalendarMonth
    "Campaign" -> Icons.Default.Campaign
    "Category" -> Icons.Default.Category
    "Celebration" -> Icons.Default.Celebration
    "Chat" -> Icons.AutoMirrored.Filled.Chat
    "CheckCircle" -> Icons.Default.CheckCircle
    "Checklist" -> Icons.Default.Checklist
    "Construction" -> Icons.Default.Construction
    "ContentCut" -> Icons.Default.ContentCut
    "CreditCard" -> Icons.Default.CreditCard
    "CurrencyExchange" -> Icons.Default.CurrencyExchange
    "Dashboard" -> Icons.Default.Dashboard
    "Delete" -> Icons.Default.Delete
    "Description" -> Icons.Default.Description
    "Download" -> Icons.Default.Download
    "Draw" -> Icons.Default.Draw
    "Eco" -> Icons.Default.Eco
    "Email" -> Icons.Default.Email
    "EventNote" -> Icons.Default.EventNote
    "FactCheck" -> Icons.Default.FactCheck
    "Flag" -> Icons.Default.Flag
    "Flight" -> Icons.Default.Flight
    "FolderShared" -> Icons.Default.FolderShared
    "Forum" -> Icons.Default.Forum
    "Groups" -> Icons.Default.Groups
    "History" -> Icons.Default.History
    "Home" -> Icons.Default.Home
    "HourglassBottom" -> Icons.Default.HourglassBottom
    "Inbox" -> Icons.Default.Inbox
    "Key" -> Icons.Default.Key
    "Label" -> Icons.Default.Label
    "Language" -> Icons.Default.Language
    "Layers" -> Icons.Default.Layers
    "ListAlt" -> Icons.AutoMirrored.Filled.ListAlt
    "LocalOffer" -> Icons.Default.LocalOffer
    "LocalShipping" -> Icons.Default.LocalShipping
    "LocationOn" -> Icons.Default.LocationOn
    "ManageAccounts" -> Icons.Default.ManageAccounts
    "MenuBook" -> Icons.AutoMirrored.Filled.MenuBook
    "MoveToInbox" -> Icons.Default.MoveToInbox
    "NightsStay" -> Icons.Default.NightsStay
    "Notifications" -> Icons.Default.Notifications
    "Payments" -> Icons.Default.Payments
    "People" -> Icons.Default.People
    "Percent" -> Icons.Default.Percent
    "Person" -> Icons.Default.Person
    "PersonAdd" -> Icons.Default.PersonAdd
    "PieChart" -> Icons.Default.PieChart
    "Place" -> Icons.Default.Place
    "PointOfSale" -> Icons.Default.PointOfSale
    "Public" -> Icons.Default.Public
    "QrCode" -> Icons.Default.QrCode
    "Receipt" -> Icons.Default.Receipt
    "ReceiptLong" -> Icons.Default.ReceiptLong
    "RequestQuote" -> Icons.Default.RequestQuote
    "Rule" -> Icons.AutoMirrored.Filled.Rule
    "Schedule" -> Icons.Default.Schedule
    "School" -> Icons.Default.School
    "Settings" -> Icons.Default.Settings
    "ShoppingBag" -> Icons.Default.ShoppingBag
    "ShoppingCart" -> Icons.Default.ShoppingCart
    "ShowChart" -> Icons.AutoMirrored.Filled.ShowChart
    "Storage" -> Icons.Default.Storage
    "Storefront" -> Icons.Default.Storefront
    "SwapHoriz" -> Icons.Default.SwapHoriz
    "TableChart" -> Icons.Default.TableChart
    "Translate" -> Icons.Default.Translate
    "Tune" -> Icons.Default.Tune
    "Undo" -> Icons.AutoMirrored.Filled.Undo
    "Verified" -> Icons.Default.Verified
    "VerifiedUser" -> Icons.Default.VerifiedUser
    "ViewColumn" -> Icons.Default.ViewColumn
    "ViewInAr" -> Icons.Default.ViewInAr
    "ViewQuilt" -> Icons.Default.ViewQuilt
    "Warning" -> Icons.Default.Warning
    "WbSunny" -> Icons.Default.WbSunny
    else -> Icons.Default.Circle
}
