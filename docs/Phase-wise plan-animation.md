Phase-wise plan
Phase 1: Room entry + realtime — DONE (code + backend tests, 2026-10-29). Phone par abhi test baaki: Reverb ke liye `adb reverse tcp:8080 tcp:8080`, Expo `-c` se restart, aur ek user ko entrance effect/banner equip karke join karwana. Vehicle abhi image hai, SVGA nahi.

"Entering Room" dim overlay aur spinner.
Chat mein entry pill: badges + "X entered the room".
Entry banner: right se slide hoke dheere rukta hai (~2.5s), naam scroll hota hai, phir nikal jaata hai. Ek ke baad ek queue mein chalte hain.
Vehicle entry: agar user ke paas entrance effect equip hai to gaadi screen par chalti hai. Abhi placeholder image, baad mein SVGA.
Backend:
member.joined, member.left aur entrance.effect events.
Equip ke liye user_store_items.equipped_at column aur POST /me/store-items/{id}/equip.
Phase 2: Seat aur mic

Khaali seat par tap karte hi seedha baith jaana (abhi bhi aisa hai).
Apni seat par tap karne par sheet: View profile / Leave the Mic / Send gift / Cancel. Abhi Alert aata hai.
"Go on…" invite: khaali seat par haath ka animation.
Mic ring: bolte waqt avatar ke chaaron taraf safed ring bahar phailti hai. Awaaz ka data nahi hai, isliye abhi mic-on par chalegi. speaking.updated aane par asli bolne par chalegi.
Phase 3: Gift animations

Gift sheet:
Upar seat wale logon ke avatars + "ALL" (pehle se selected).
Ek user ki profile se kholo to "Send → Naam".
Quantity 1 / 7 / 77 / 177.
Send karne par:
Gift beech mein bada hota hai, phir receiver ki seat tak udd ke chhota hota hai.
ALL par copies ek saath har seat par jaati hain.
Left mein "X send Y [icon] xN" pills, alag lanes mein.
Combo button (ghatti ring, ~3s), aur x1 → x2 → … number pop hota hai.
Chat mein gift card "To BHATTI · Blue Eyes x1".
Bade gift par floating banner upar slide hoke aata hai.
Backend: to_users[] (ALL), ek transaction mein sabka coin kata jaaye.
Phase 4: Emoji panel

Neeche se sheet, tabs: Casual / Emoji. Casual mein Dice, Rock-Paper-Scissors, Lucky 777.
Seat avatar ke upar ~2.25s emoji. Dice/RPS/777 ka ghoomna phir result. Chat mein sirf result.
~2s cooldown.
Backend: POST /rooms/{uuid}/emoji. Dice ka result server decide karega, warna koi cheat kar sakta hai. Phir event sab ko jaayega.
Phase 5: Baaki room UI

Exit ke liye power overlay (Minimize / Exit gol buttons).
Minimize par neeche floating pill, ghoomti room photo ke saath.
Chat filters All / Gifts / Chat + Clear + expand ↗ + "New ⌄" pill.
Effect Settings toggles (Entry / Floating / Gift effects off karna).
