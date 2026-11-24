package ke.co.bethanyhouse.hub.ui.features

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Test

class ProfileIdentifiersTest {
    @Test fun onlyPresentMaskedIdentifiersAreShown() {
        val person = Json.parseToJsonElement("""{"masked":{"national_id_no":"***1234","kra_pin":"","nssf_no":null,"shif_no":"   ","bank_account_no":"***9876"}}""")
        assertEquals(listOf("National ID" to "***1234", "Bank account" to "***9876"), ownIdentifiers(person))
    }

    @Test fun missingMaskedDataShowsNoIdentifiers() {
        assertEquals(emptyList<Pair<String, String>>(), ownIdentifiers(Json.parseToJsonElement("""{}""")))
        assertEquals(emptyList<Pair<String, String>>(), ownIdentifiers(null))
    }
}
