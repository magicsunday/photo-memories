## Feed user preference JSON schema

The feed preference storage keeps user-specific toggles in a JSON document located at the path configured for `FeedUserPreferenceStorage`. The document has the following nested structure:

```json
{
  "users": {
    "<user-id>": {
      "profiles": {
        "<profile-key>": {
          "favourites": [],
          "hidden_algorithms": [],
          "hidden_persons": [],
          "hidden_pets": [],
          "hidden_places": [],
          "hidden_dates": [],
          "favourite_persons": [],
          "favourite_places": []
        }
      }
    }
  }
}
```

All lists contain string identifiers and are stored without duplicates or empty values. Existing files that pre-date the extended schema are automatically migrated on read; missing keys are added with empty arrays before the document is written back to disk.

Use the dedicated setters on `FeedUserPreferenceStorage` to update each list (`setHiddenPersons`, `setHiddenPets`, `setHiddenPlaces`, `setHiddenDates`, `setFavouritePersons`, `setFavouritePlaces`) to ensure consistent normalisation.
