# Google Calendar scope verification demo

Target length: 6-9 minutes. Record the browser window continuously with no cuts, static slides, or hidden consent steps.

## Test identities and data

- Koopo beta: `https://beta.koopoonline.com`
- Connected Google account: `plu2oprinze@gmail.com` (Prince Arnett)
- Destination calendar: `myprinzeamir@gmail.com`
- Destination calendar owner: Prinze Amir (`myprinzeamir@gmail.com`)
- Connected user's access: **Make changes and see all event details**
- Booking profile: **Bowers School Farm — Business**
- Test service: **Ice Sculturing Class**
- Privacy mode: **Minimal — service and business only**

## One-take narration and actions

1. **Purpose and data boundary**
   - Open Koopo beta > Seller Dashboard > Appointment Settings.
   - Select **Bowers School Farm — Business**.
   - Read the Calendar Sync disclosure on screen.
   - Say: “Koopo is an appointment marketplace. Providers choose an existing Google calendar as the destination for confirmed Koopo bookings. Existing Google events return only busy time. Koopo never imports external titles or descriptions, and external calendar changes never modify Koopo appointments.”

2. **Why `calendar.events` is necessary**
   - Open Google Calendar > Settings.
   - Under **Settings for other calendars**, open `myprinzeamir@gmail.com`.
   - Keep **Owner: Prinze Amir** and **Prince Arnett — Make changes and see all event details** visible.
   - Say: “This calendar is shared with the connected account but is not owned by it. Koopo must create, update, and remove mirrored appointment events here. `calendar.events.owned` cannot write to this non-owned calendar.”

3. **OAuth consent screen and exact scopes**
   - Return to Koopo and click **Connect**.
   - Select `plu2oprinze@gmail.com`.
   - **Manual safety handoff:** on “Google hasn’t verified this app,” the account owner must personally open **Advanced** and continue to Koopo. Do not automate or omit this browser security step.
   - Continue past the identity page.
   - On **Koopo wants additional access**, keep these readable:
     - “See the list of Google calendars you’re subscribed to.”
     - “View and edit events on all your calendars.”
   - Open **3 services** and show the three existing identity services; click **Done**.
   - Select all requested permissions and click **Continue**.
   - Back in Koopo, show `plu2oprinze@gmail.com · Connected`.
   - Show the destination selector containing the primary calendar, Business-Work, and `myprinzeamir@gmail.com`.
   - Select `myprinzeamir@gmail.com`, keep Minimal event details, and save.

4. **Create and source-account impact**
   - Open My Appointments and create a confirmed Ice Sculturing Class appointment for the prepared test time.
   - Show the confirmed appointment in Koopo.
   - Open Google Calendar and show the mirrored appointment on `myprinzeamir@gmail.com`.
   - Open its details so the calendar name and Koopo-generated content are readable.

5. **Update and source-account impact**
   - Return to Koopo and reschedule the appointment to the prepared second time.
   - Return to Google Calendar and show the same mirrored event moved to the new time.

6. **Read busy time without importing event content**
   - In Google Calendar, create `Reviewer Busy-Time Test` on `myprinzeamir@gmail.com` at the prepared busy-time slot.
   - In Koopo, open the availability/appointment form for the same service and date.
   - Show that the time is unavailable while the external title and description are not displayed.
   - Say: “Koopo uses the selected calendar only for start, end, and busy status. It discards external titles and descriptions.”

7. **Delete and source-account impact**
   - Cancel the Koopo appointment.
   - Return to Google Calendar and show that Koopo removed only its mirrored appointment event.
   - Show that `Reviewer Busy-Time Test` still exists, proving Koopo does not alter pre-existing external events.
   - Delete the temporary busy-time test event after the demonstration.

8. **Close with least privilege**
   - Say: “Koopo requests `calendar.calendarlist.readonly` to list selectable calendars and `calendar.events` to read busy status and manage only Koopo-mirrored events in the selected writable calendar. Read-only or free/busy scopes cannot create, update, or delete. App-created-calendar scope would force a separate Koopo calendar. The broad calendar scope is not requested.”

## Rehearsal evidence (August 27, 2026)

- Beta only; production was not changed.
- Connected `plu2oprinze@gmail.com` and selected the writable shared calendar `myprinzeamir@gmail.com`.
- Confirmed Google shows the connected account can **Make changes and see all event details** while the calendar is owned by `myprinzeamir@gmail.com`.
- Created confirmed booking **#49**, Ice Sculturing Class, August 31, 2026 from 2:00–3:00 PM.
- Confirmed Google created the mirrored event on `myprinzeamir@gmail.com`.
- Rescheduled booking #49 to 3:00–4:00 PM and confirmed Google moved the mirrored event.
- Created `Reviewer Busy-Time Test` from 10:00–11:00 AM; Koopo omitted 10:00 AM from availability without displaying the external title.
- Cancelled booking #49 and confirmed Google removed only the Koopo-managed event.
- Deleted the temporary reviewer event, refreshed busy-time import, and confirmed 10:00 AM returned to Koopo availability.
- Booking #49 remains as a cancelled beta business record. The temporary Google events were removed.

## Current recording checkpoint

- Koopo was disconnected after cleanup so the final take can show OAuth from the beginning.
- Selecting `plu2oprinze@gmail.com` reaches Google’s **Google hasn’t verified this app** interstitial.
- The account owner must perform the one manual safety-handoff step described above. Resume the uninterrupted take immediately afterward and follow steps 3–8.
- Do not upload the earlier long desktop recording; it contains unrelated activity from recorder setup and is not a reviewer-ready artifact.

## Google reviewer reply

Hello Third-Party Data Safety Team,

Thank you for the clarification. We updated our Google Cloud scope justification and recorded a new uninterrupted live demonstration of the production-ready Google Calendar integration in our staging environment.

The video shows:

- Koopo’s appointment-booking purpose and user-facing Calendar Sync disclosure.
- The complete OAuth consent flow with all requested permissions readable and the three existing identity services expanded.
- `calendar.calendarlist.readonly` listing the user’s selectable calendars.
- Why `calendar.events` is required: the provider selects an existing shared calendar owned by another Google account, while the connected user has permission to edit events. `calendar.events.owned` cannot support this non-owned shared-calendar workflow.
- A confirmed Koopo appointment being created in the selected Google calendar, rescheduled, and removed after cancellation, with each change shown in the source Google Calendar account.
- An existing Google event blocking Koopo availability without its title or description being imported, and remaining untouched when the Koopo appointment is cancelled.
- That Koopo requests no broader Calendar scope and modifies only Koopo-mirrored appointment events.

New demonstration video: [INSERT UNLISTED YOUTUBE URL]

The scopes requested by the application exactly match the scopes submitted in Google Cloud:

- `https://www.googleapis.com/auth/calendar.events`
- `https://www.googleapis.com/auth/calendar.calendarlist.readonly`
- `openid`
- `email`
- `profile`

Please continue the verification review. Thank you.
