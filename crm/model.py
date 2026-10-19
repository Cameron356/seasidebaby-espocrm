#!/usr/bin/env python3
"""The Seaside office book's data model, in one place.

Declares every record type and field, then writes the EspoCRM metadata file tree
(entityDefs, scopes, clientDefs, i18n, layouts, PHP stubs) that install.py copies into a
container. Same file layout as Pack Leader's configs/espocrm/lesson-package/, generated
instead of hand-written so a field is added in one line.

Field names match the booking form (site/book.html) so a submission maps straight onto
a Request: form `baby[dueDate]` -> `babyDueDate`, `kids[i][x]` -> RequestChild.x.

Record types (labels in the office):
  Lead          -> Request        what the form makes; Amanda decides on it
  Account       -> Family         one household: home, emergency, health, insurance
  Contact       -> Parent
  Child         (new)             one child, belongs to a Family
  RequestChild  (new)             a child as written on one request (copied to Child on approval)
  CarePlan      -> Postpartum plan (new)  THE core record: one family's Postpartum Doula care (package
                                  or custom hours, bought up front; hours bought / scheduled / done / left, shift
                                  pattern, baby, recovery notes, package invoice)
  CareBooking   -> Booking (new)  one booked period of care; a postpartum shift belongs to a plan
  ShiftLog      (new)             notes from one shift; postpartum logs are shared with parents
  Meeting       -> Consultation

  python3 model.py OUTDIR    # write the tree (install.py does this itself)
"""
import json, os, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "scripts"))
import seaside_pricing as P   # noqa: E402  postpartum plan options and minimums

# Postpartum Doula care is the main service: first in every list, its own record type (CarePlan).
SERVICES = [("pp", "Postpartum Doula care"), ("nanny", "Daytime nanny"), ("sit", "Babysitting"),
            ("getaway", "Parents' getaway"), ("week", "Vacation week"), ("travel", "Travel nanny"),
            ("unsure", "Not sure yet")]
AWAY = ["getaway", "week"]


# ---- field helpers ----------------------------------------------------------------------
# f(name, type, label, **extra). Extras: options=[(value,label)], only=[services] (show only
# for those services on a Request), req=True, tip="tooltip", full=True (full-width in layouts),
# sensitive=True (documentation: never shown outside the office).
def f(name, typ, label, **x):
    return dict(name=name, type=typ, label=label, **x)


def opts(*labels):
    return [(l, l) for l in labels]


YESNO = opts("Yes", "No")

# Family-level answers: on the Request (as sent) and on the Family (kept, newest wins).
FAMILY = {
    "Emergency contacts": [
        f("ec1Name", "varchar", "Emergency contact"), f("ec1Rel", "varchar", "Relationship"),
        f("ec1Phone", "varchar", "Emergency phone"),
        f("ec2Name", "varchar", "Second emergency contact"), f("ec2Rel", "varchar", "Relationship (2nd)"),
        f("ec2Phone", "varchar", "Second emergency phone"),
        f("pickupOk", "text", "Also may pick up", full=True),
        f("codeWord", "varchar", "Family code word", sensitive=True),
        f("pickupNo", "text", "May NOT pick up", full=True, tip="Shown in bold on the Tonight board."),
    ],
    "Getting in": [
        f("contactPref", "enum", "Best way to reach", options=opts("", "Text", "Call", "Email")),
        f("arrival", "text", "Gate, parking, which door", sensitive=True, full=True),
        f("p2Name", "varchar", "Second parent"), f("p2Phone", "varchar", "Second parent phone"),
        f("p2Email", "varchar", "Second parent email"),
    ],
    "Health and insurance": [
        f("pedName", "varchar", "Pediatrician"), f("pedPhone", "varchar", "Pediatrician phone"),
        f("hospital", "varchar", "Preferred urgent care / hospital"),
        f("insCompany", "varchar", "Insurance company"), f("insMember", "varchar", "Member ID", sensitive=True),
        f("insGroup", "varchar", "Group number", sensitive=True), f("insHolder", "varchar", "Policyholder"),
        f("insHolderDob", "date", "Policyholder date of birth", sensitive=True),
        f("insCardFront", "file", "Insurance card (front)", sensitive=True),
        f("insCardBack", "file", "Insurance card (back)", sensitive=True),
        f("insOther", "text", "Other plan(s)", sensitive=True, full=True),
    ],
    "Home": [
        f("pets", "enum", "Pets", options=[("", ""), ("no", "No pets"), ("yes", "Yes")]),
        f("petNotes", "varchar", "Pets: kind, names, friendly?"),
        f("smokeFree", "enum", "Smoke-free home", options=[("", "")] + YESNO),
        f("pool", "enum", "Pool or open water", options=[("", ""), ("no", "No"), ("fenced", "Yes, fenced or alarmed"), ("open", "Yes, not fenced")],
          style={"open": "danger"}),
        f("cameras", "varchar", "Cameras in the home"),
        f("alarmWifi", "text", "Alarm code and Wi-Fi", sensitive=True, full=True),
    ],
    "Driving": [
        f("mayDrive", "enum", "May Amanda drive the kids", options=[("", ""), ("no", "No driving needed"), ("yes", "Yes")]),
        f("whoseCar", "varchar", "Whose car"), f("carSeats", "varchar", "Car seats"),
    ],
    "How the family does things": [
        f("screens", "varchar", "Screen time"), f("discipline", "varchar", "Discipline"),
        f("foodRules", "varchar", "Food rules"), f("languages", "varchar", "Languages at home"),
        f("houseRules", "text", "House rules", full=True),
    ],
}

# Child-level answers: on RequestChild (as sent) and on Child (kept).
CHILD = [
    f("birthDate", "date", "Date of birth"),   # required by the form and SeasideRequest.php; a baby on the way has none
    f("allergies", "varchar", "Allergies", tip="Shown in bold on the Tonight board."),
    f("epipen", "varchar", "EpiPen / inhaler kept at"),
    f("medications", "varchar", "Medications (what, dose, when)"),
    f("conditions", "varchar", "Conditions / special needs"),
    f("sleep", "varchar", "Bedtime and naps"),
    f("feeding", "varchar", "Feeding (babies)"), f("sleepWhere", "varchar", "Sleeps in (babies)"),
    f("food", "varchar", "Food: eats / avoids"),
    f("school", "varchar", "School or daycare (name, times)"),
    f("activities", "varchar", "Activities"), f("comfort", "varchar", "Comfort items, what calms them"),
    f("notes", "text", "Anything else", full=True),
]

# The Request (Lead). Groups become panels; `only` hides a field for other services.
REQUEST = {
    "Decision": [
        f("status", "enum", "Status", options=[("New", "New"), ("Consultation", "Consultation scheduled"), ("Quoted", "Quoted"),
                                             ("Approved", "Approved"), ("Declined", "Declined")],
          style={"New": "primary", "Consultation": "info", "Quoted": "warning", "Approved": "success", "Declined": "danger"},
          core=True),
        f("service", "enum", "Service", options=SERVICES, req=True),
        f("ppPlan", "enum", "Postpartum plan", options=[("", "")] + P.pp_plan_options(), only=["pp"],
          tip="Required before approving postpartum care: the package you agreed with the family, or Custom hours (then set "
              "Custom hours). Approving makes their Postpartum plan and, with payments on, sends the invoice for it."),
        f("ppHours", "int", "Custom hours", only=["pp"],
          tip="For Custom hours: how many hours the family is buying (8 or more), billed at the hourly rate when you approve."),
        f("estimate", "float", "Form's estimate ($)", tip="What the booking page showed the family. The invoice is priced from scripts/seaside_pricing.py."),
        f("agreedRate", "float", "Agreed rate ($/hr)", only=["nanny", "travel", "unsure"],
          tip="Required before approving a custom-quote service. The robot won't invoice without it."),
        f("priceOverride", "float", "Price (override) ($)", tip="Replaces the price-list total for this one request."),
        f("discount", "enum", "Discount", options=opts("", "Returning family", "Referral", "Other")),
        f("noteToClient", "text", "Note to the family", full=True, tip="Goes into the approval or decline email word for word."),
        f("officeRobot", "text", "Office robot", full=True, tip="Why the robot is waiting, or what it did."),
        f("returning", "bool", "Returning family"),
        f("family", "link", "Family", entity="Account", foreign="familyRequests"),
    ],
    "When": [
        f("careStart", "enum", "Start", options=[("", ""), ("date", "On a date"), ("asap", "As soon as possible"), ("afterBirth", "After baby arrives"), ("flexible", "Flexible")],
          only=["pp", "nanny", "travel", "unsure"]),
        f("careStartDate", "date", "Start date", only=["pp", "nanny", "travel", "unsure"]),
        f("careLength", "varchar", "How long", only=["pp", "nanny", "travel", "unsure"]),
        f("startAt", "datetime", "Starts / parents leave", only=["sit"] + AWAY),
        f("endAt", "datetime", "Ends / parents back", only=["sit"] + AWAY),
        f("sitRegular", "varchar", "One time or regular", only=["sit"]),
        f("ppNightsPerWeek", "varchar", "Nights per week", only=["pp"]),
        f("ppShiftStart", "varchar", "Shift starts", only=["pp"]),
        f("ppShiftHours", "int", "Shift length (hours)", only=["pp"]),
        f("ppInterest", "enum", "Thinking about", options=[("", ""), ("package", "A package of hours"), ("hours", "A set number of hours"),
                                                            ("unsure", "Not sure yet, help us choose")], only=["pp"]),
        f("nannyDays", "multiEnum", "Nanny days", options=opts("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"), only=["nanny"]),
        f("nannyFrom", "varchar", "Nanny from", only=["nanny"]), f("nannyTo", "varchar", "Nanny to", only=["nanny"]),
        f("tripDestination", "varchar", "Trip destination", only=["travel"]),
        f("tripStart", "date", "Trip starts", only=["travel"]), f("tripEnd", "date", "Trip ends", only=["travel"]),
        f("tripBy", "varchar", "Traveling by", only=["travel"]), f("tripRoom", "varchar", "Own room", only=["travel"]),
        f("tripPassport", "varchar", "Passport", only=["travel"]),
        f("tripHours", "text", "On-duty hours and days off", only=["travel"], full=True),
        f("meetTimes", "text", "Best times to talk", only=["nanny", "travel", "unsure"]),
        f("meetHow", "varchar", "Meet how", only=["nanny", "travel", "unsure"]),
    ],
    "Baby": [
        f("babyBorn", "enum", "Baby arrived", options=[("", ""), ("no", "Not yet"), ("yes", "Yes")], only=["pp"]),
        f("babyDueDate", "date", "Due date", only=["pp"]), f("babyBirthDate", "date", "Baby's birth date", only=["pp"]),
        f("babyName", "varchar", "Baby's name", only=["pp"]), f("babyCount", "varchar", "How many", only=["pp"]),
        f("babyFeeding", "varchar", "Feeding plan", only=["pp"]), f("babyBringToMom", "varchar", "Night feeds", only=["pp"]),
        f("babySleepWhere", "varchar", "Baby sleeps in", only=["pp"]),
        f("babyFeedingNotes", "text", "Formula, bottles, swaddle, white noise", only=["pp"], full=True),
        f("babyHealth", "varchar", "Baby: allergies, medications, health", only=["pp"]),
        f("babyBirthPlace", "varchar", "Hospital / birth center", only=["pp"]),
        f("babyBirthType", "varchar", "Birth type", only=["pp"]),
        f("babySupport", "text", "Recovery and support", only=["pp"], full=True),
    ],
    "While Amanda's there": [
        f("sitBedtime", "varchar", "Bedtime during booking", only=["sit"]), f("sitBath", "varchar", "OK to bathe", only=["sit"]),
        f("sitWhere", "varchar", "Parents will be", only=["sit"]),
        f("awaySchedule", "text", "Daily schedule", only=AWAY, full=True),
        f("nannyMeals", "varchar", "Prepare meals", only=["nanny"]), f("nannyPickups", "varchar", "Pickups / drop-offs", only=["nanny"]),
        f("nannyChores", "varchar", "Light chores", only=["nanny"]),
    ],
    "Agreement": [
        f("medAuthName", "varchar", "Medical authorization signed by", only=AWAY + ["travel"]),
        f("medAuthDate", "date", "Signed on", only=AWAY + ["travel"]),
        f("agreePolicies", "bool", "Agreed to care policies"), f("agreeAccurate", "bool", "Confirmed accurate"),
        f("heardFrom", "varchar", "Heard about us from"),
    ],
}

# Only on the Family: their private page (FamilyPage.php). Made by the robot; clear it to reset the link.
FAMILY_ONLY = [f("familyLink", "varchar", "Family page link", maxLength=255,
                 tip="The family's private page (upcoming bookings, shared shift notes). It's in the robot's emails. "
                     "Clear this field to make the old link stop working; the next email gets a new one.")]

# Robot bookkeeping on the Request (checkpoints so a re-run never sends twice). Not in the layouts.
ROBOT = [f("thanksSentAt", "datetime", "Thank-you email sent"), f("officeNotifiedAt", "datetime", "Office notified"),
         f("decisionSentAt", "datetime", "Decision email sent")]

# New record types. Each: label, plural, icon, fields, links.
CHILD_ENTITY = dict(
    label="Child", plural="Children", icon="fas fa-child",
    fields=[f("name", "varchar", "First name", req=True), f("family", "link", "Family", entity="Account", foreign="children", req=True),
            f("dueDate", "date", "Due date (not born yet)")] + CHILD,
    list=["name", "family", "birthDate", "allergies"],
)
REQUEST_CHILD = dict(
    label="Child on request", plural="Children on requests", icon="fas fa-child", tab=False,
    fields=[f("name", "varchar", "First name", req=True), f("request", "link", "Request", entity="Lead", foreign="requestChildren", req=True),
            f("child", "link", "Child record", entity="Child", foreign="requestEntries",
              tip="Set by the robot on approval: the Child this became.")] + CHILD,
    list=["name", "request", "birthDate", "allergies"],
)
BOOKING = dict(
    label="Booking", plural="Bookings", icon="fas fa-calendar-check",
    fields=[
        f("name", "varchar", "Booking", req=True),
        f("service", "enum", "Service", options=SERVICES[:-1], req=True),
        f("status", "enum", "Status", options=[("Requested", "Requested"), ("Confirmed", "Confirmed"), ("InProgress", "In progress"),
                                               ("Done", "Done"), ("Cancelled", "Cancelled")],
          style={"Requested": "default", "Confirmed": "primary", "InProgress": "warning", "Done": "success", "Cancelled": "danger"}, default="Confirmed"),
        # dateStart/dateEnd (datetimeOptional) + an assigned user are what EspoCRM's calendar needs.
        f("dateStart", "datetimeOptional", "Starts", req=True), f("dateEnd", "datetimeOptional", "Ends", req=True),
        f("family", "link", "Family", entity="Account", foreign="careBookings", req=True),
        f("contact", "link", "Parent", entity="Contact", foreign="careBookings"),
        f("children", "linkMultiple", "Children", entity="Child", foreign="careBookings", relationName="careBookingChild"),
        f("request", "link", "Request", entity="Lead", foreign="careBookings"),
        f("carePlan", "link", "Postpartum plan", entity="CarePlan", foreign="shifts",
          tip="A postpartum shift belongs to the family's plan: its hours count against the package, and it's "
              "marked In package (nothing to pay). Shifts made from the plan are linked already."),
        f("rate", "float", "Rate ($)"), f("rateUnit", "enum", "Per", options=opts("hour", "night", "flat")),
        f("hours", "float", "Hours"), f("nights", "int", "Nights"), f("total", "float", "Total ($)"),
        f("payment", "enum", "Payment", options=opts("Unpaid", "Invoiced", "Paid", "Paid another way", "In package"), default="Unpaid",
          style={"Unpaid": "warning", "Invoiced": "info", "Paid": "success", "Paid another way": "success", "In package": "primary"},
          tip="Invoiced = the family has a Stripe pay link. Paid turns on by itself when Stripe says so. "
              "Set 'Paid another way' for cash or Zelle: the robot closes the Stripe invoice."),
        f("sendInvoice", "bool", "Send invoice",
          tip="Tick to have the robot send a Stripe invoice for this booking (for shifts you add by hand). "
              "Approved babysitting and getaway bookings are invoiced without it, once Stripe is switched on."),
        f("paymentDue", "datetime", "Payment due"), f("payLink", "varchar", "Card pay link", maxLength=255),
        f("paidAt", "datetime", "Paid at"), f("stripeInvoiceId", "varchar", "Stripe invoice (card)"),
        f("bankPayLink", "varchar", "Bank pay link", maxLength=255), f("bankInvoiceId", "varchar", "Stripe invoice (bank)"),
        f("description", "text", "Notes for this booking", full=True),
    ],
    list=["name", "service", "dateStart", "dateEnd", "status", "payment", "carePlan"],
    scope={"calendar": True, "statusField": "status", "completedStatusList": ["Done"], "canceledStatusList": ["Cancelled"]},
    color="#2F6F86",
)
SHIFT_LOG = dict(
    label="Shift log", plural="Shift logs", icon="fas fa-book-open",
    fields=[
        f("name", "varchar", "Shift", req=True),
        f("careBooking", "link", "Booking", entity="CareBooking", foreign="shiftLogs"),
        f("family", "link", "Family", entity="Account", foreign="shiftLogs", req=True),
        f("shiftStart", "datetime", "Shift started"), f("shiftEnd", "datetime", "Shift ended"),
        f("feeds", "text", "Feeds (time, oz or side)", full=True), f("diapers", "text", "Diapers", full=True),
        f("sleep", "text", "Sleep stretches / naps", full=True),
        f("parentRest", "text", "Parents' rest (who slept, how long)", full=True),
        f("soothing", "text", "Soothing and settling (what worked)", full=True),
        f("supplies", "text", "Running low (diapers, wipes, formula)", full=True),
        f("meals", "text", "Meals and activities", full=True),
        f("notesForParents", "text", "Notes for the parents", full=True),
        f("privateNotes", "text", "Private notes (office only)", full=True),
        f("shared", "bool", "Shared with the parents", tip="Parents see a log on their family page once this is ticked, and the robot emails it to them."),
        f("sharedEmailAt", "datetime", "Emailed to the parents"),
    ],
    list=["name", "family", "shiftStart", "shared"],
)
# The Postpartum plan: one family's Postpartum Doula care, from approval to the last shift.
# Hours are counted by the office itself (Hooks/CarePlan/Hours.php) from the plan's shifts:
#   Scheduled = shifts still to come (Requested, Confirmed, In progress)   Done = shifts marked Done
#   Left      = hours bought - scheduled - done (every plan has hours bought up front)
PLAN_STATUS = [("WaitingForBaby", "Waiting for baby"), ("Scheduling", "Ready to schedule"), ("Active", "Active"),
               ("Finished", "Finished"), ("Cancelled", "Cancelled")]
SHIFT_STARTS = [("19:00", "7 PM"), ("20:00", "8 PM"), ("21:00", "9 PM"), ("22:00", "10 PM"), ("23:00", "11 PM")]
CARE_PLAN = dict(
    label="Postpartum plan", plural="Postpartum plans", icon="fas fa-moon", color="#9A5752",
    panels=[
        ("Plan", [
            f("name", "varchar", "Plan", req=True),
            f("status", "enum", "Status", options=PLAN_STATUS, default="Scheduling",
              style={"WaitingForBaby": "info", "Scheduling": "warning", "Active": "success", "Finished": "default", "Cancelled": "danger"},
              tip="Waiting for baby until the birth (the family can tell you from their page). Active once a shift is on the "
                  "calendar. Finished by itself when a package's hours are all used."),
            f("plan", "enum", "Package", options=P.pp_plan_options(), req=True,
              tip="Changing it doesn't change Hours bought, Rate or Price: set those too (the robot fills them when it makes the plan)."),
            f("family", "link", "Family", entity="Account", foreign="carePlans", req=True),
            f("contact", "link", "Parent", entity="Contact", foreign="carePlans"),
            f("baby", "link", "Baby", entity="Child", foreign="babyPlans"),
            f("request", "link", "Request", entity="Lead", foreign="carePlans"),
            f("hoursBought", "float", "Hours bought", tip="Paid for up front. Add hours here if the family buys more (and bill them for it)."),
            f("rate", "float", "Rate ($/hr)"), f("price", "float", "Package price ($)"),
            f("hoursScheduled", "float", "Hours scheduled", tip="Shifts still to come. Counted by the office."),
            f("hoursDone", "float", "Hours done", tip="Shifts marked Done. Counted by the office."),
            f("hoursLeft", "float", "Hours left to schedule", tip="Hours bought - scheduled - done. Counted by the office."),
        ]),
        ("Shift pattern", [
            f("startDate", "date", "First night"),
            f("nights", "multiEnum", "Nights", options=opts("Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat")),
            f("shiftStart", "enum", "Shift starts", options=SHIFT_STARTS, default="22:00"),
            f("shiftHours", "int", "Shift length (hours)", default=P.PP_MIN_HOURS),
            f("scheduleThrough", "date", "Make shifts through",
              tip="Last night to put on the calendar. Default: two weeks from the first night."),
            f("makeShifts", "bool", "Make the shifts",
              tip="Tick and save: within 5 minutes the robot puts a shift on each chosen night from First night to Make shifts "
                  "through (skipping nights that already have one, and stopping when a package runs out), then emails the "
                  "family their schedule. It unticks this when done."),
            f("officeRobot", "text", "Office robot", full=True, tip="What the robot did, or why it's waiting."),
        ]),
        ("Baby and recovery", [
            f("feedingPlan", "varchar", "Feeding plan"), f("nightFeeds", "varchar", "Night feeds"),
            f("birthPlace", "varchar", "Hospital / birth center"), f("birthType", "varchar", "Birth type"),
            f("supportNotes", "text", "Recovery and support", full=True, tip="From the request; shown on the Tonight board."),
            f("babyArrivedAt", "datetime", "Family said baby arrived", tip="Set when the family taps Baby's here on their page."),
        ]),
        ("Package payment", [
            f("payment", "enum", "Payment", options=opts("Unpaid", "Invoiced", "Paid", "Paid another way"), default="Unpaid",
              style={"Unpaid": "warning", "Invoiced": "info", "Paid": "success", "Paid another way": "success"},
              tip="Every plan is paid in full up front. With payments on, the invoice goes out when the request is approved."),
            f("sendInvoice", "bool", "Send package invoice",
              tip="Tick to have the robot send a Stripe invoice for the package price (once Stripe is switched on)."),
            f("paymentDue", "datetime", "Payment due"), f("payLink", "varchar", "Card pay link", maxLength=255),
            f("paidAt", "datetime", "Paid at"), f("stripeInvoiceId", "varchar", "Stripe invoice (card)"),
        f("bankPayLink", "varchar", "Bank pay link", maxLength=255), f("bankInvoiceId", "varchar", "Stripe invoice (bank)"),
        ]),
        ("More hours", [
            f("topupHours", "int", "Hours to add",
              tip="The family asks from their page, or you type it and set More hours to Requested. The robot prices it "
                  "(100 or more at their earned rate, fewer at the hourly rate), sends bank and card invoices, and adds "
                  "the hours to Hours bought when it's paid."),
            f("topupStatus", "enum", "More hours", options=opts("", "Requested", "Invoiced", "Paid", "Paid another way", "Cancelled"),
              style={"Requested": "warning", "Invoiced": "info", "Paid": "success", "Paid another way": "success", "Cancelled": "danger"},
              tip="Paid another way (cash, Zelle): the robot closes the Stripe invoices and adds the hours. Cancelled: it "
                  "cancels the invoices and adds nothing."),
            f("topupRate", "float", "Rate for the added hours ($/hr)"), f("topupTotal", "float", "Added hours total ($, by bank)"),
            f("topupPayLink", "varchar", "Card pay link (added hours)", maxLength=255),
            f("topupBankPayLink", "varchar", "Bank pay link (added hours)", maxLength=255),
            f("topupRequestedAt", "datetime", "Asked for at"), f("topupInvoiceId", "varchar", "Stripe invoice (added, card)"),
            f("topupBankInvoiceId", "varchar", "Stripe invoice (added, bank)"),
        ]),
        ("Notes", [f("description", "text", "Notes", full=True)]),
    ],
    list=["name", "status", "plan", "hoursLeft", "startDate", "payment"],
    client={"relationshipPanels": {"shifts": {"create": True, "select": True, "createHandler": "custom:handlers/care-plan-shift"}}},
    hooks=True,
)
# Robot bookkeeping on the plan (never in layouts).
PLAN_ROBOT = [f("babyAlertSentAt", "datetime", "Baby alert sent"), f("lowHoursSentAt", "datetime", "Low-hours alert sent",
              tip="Clear it to get the alert again (e.g. after the family buys more hours)."),
              ] + [f("topupOffer%dSentAt" % n, "datetime", "More-hours email sent (%d)" % n,
                     tip="The family's 'would you like more hours?' email, sent once when unused hours drop below %d. "
                         "Clears by itself when they're back at %d or more (they bought more)." % (n, n))
                   for n in P.PP_TOPUP_OFFERS]
CARE_PLAN["fields"] = [fd for _, g in CARE_PLAN["panels"] for fd in g] + PLAN_ROBOT

NEW = {"CarePlan": CARE_PLAN, "Child": CHILD_ENTITY, "RequestChild": REQUEST_CHILD, "CareBooking": BOOKING, "ShiftLog": SHIFT_LOG}

SCOPE_NAMES = {"Lead": ("Request", "Requests"), "Account": ("Family", "Families"), "Contact": ("Parent", "Parents"),
               "Meeting": ("Consultation", "Consultations")}
# Labels for the relationship panels (links added from the other side).
LINK_LABELS = {
    "Lead": {"requestChildren": "Children on this request", "careBookings": "Bookings", "meetings": "Consultations",
             "carePlans": "Postpartum plan"},
    "Account": {"children": "Children", "careBookings": "Bookings", "shiftLogs": "Shift logs", "familyRequests": "Requests",
                "contacts": "Parents", "carePlans": "Postpartum plans"},
    "Contact": {"careBookings": "Bookings", "meetings": "Consultations", "carePlans": "Postpartum plans"},
    "Child": {"careBookings": "Bookings", "requestEntries": "On requests", "babyPlans": "Postpartum plans"},
    "CareBooking": {"shiftLogs": "Shift logs"},
    "CarePlan": {"shifts": "Shifts"},
}
CREATE_LABELS = {"CarePlan": "New postpartum plan", "Lead": "New request", "Account": "New family", "Contact": "New parent", "Meeting": "New consultation",
                 "Child": "New child", "CareBooking": "New booking", "ShiftLog": "New shift log"}
# Whole panels on a Request that only apply to some services.
PANEL_ONLY = {"Baby": ["pp"], "While Amanda's there": ["sit", "nanny"] + AWAY, "Driving": ["nanny", "travel"] + AWAY}

CALENDAR_LIST = ["Meeting", "CareBooking", "Task"]   # consultations, bookings, tasks
TONIGHT_TAB = {"type": "url", "text": "Tonight", "url": "?entryPoint=tonightBoard", "iconClass": "fas fa-moon",
               "color": None, "aclScope": "CareBooking", "onlyAdmin": False, "id": "sbntonight"}
TAB_LIST = [TONIGHT_TAB, "CarePlan", "Lead", "CareBooking", "Calendar", "Account", "Contact", "Child", "ShiftLog", "Meeting", "Task"]


# ---- metadata -----------------------------------------------------------------------------
def field_def(fd):
    t = fd["type"]
    d = {"type": t, "isCustom": True}
    if fd.get("core"): d.pop("isCustom")
    if fd.get("req"): d["required"] = True
    # No readOnly: EspoCRM drops read-only fields on API writes, and the robot fills these through the
    # API (2026-10-05: a Request's Family link was silently ignored). Tooltips say who fills them.
    if fd.get("readOnly"): d["readOnly"] = True
    if fd.get("default") is not None: d["default"] = fd["default"]
    if t in ("enum", "multiEnum"):
        d["options"] = [v for v, _ in fd["options"]]
        if fd.get("style"): d["style"] = fd["style"]; d["displayAsLabel"] = True
        if t == "enum" and "default" not in d: d["default"] = d["options"][0]
    if t == "text": d["rows"] = 3
    if t == "float": d["decimalPlaces"] = 2
    # MariaDB caps a row at 64 KB and utf8mb4 reserves 4 bytes a character: the Request has ~50 short
    # fields, so 255 each overflowed it ("Row size too large", 2026-10-05). Long answers are `text`.
    if t == "varchar": d["maxLength"] = fd.get("maxLength", 150)
    if t == "enum": d["maxLength"] = 50
    if t == "linkMultiple": d.update(noLoad=False, importDisabled=True)
    if fd.get("tip"): d["tooltip"] = True
    return d


def file_links(fields):
    """A file field stores an Attachment id; EspoCRM wants a matching utility link."""
    return {fd["name"]: {"type": "belongsTo", "entity": "Attachment", "skipOrmDefs": True, "utility": True}
            for fd in fields if fd["type"] == "file"}


def link_def(fd):
    if fd["type"] == "link":
        return {"type": "belongsTo", "entity": fd["entity"], "foreign": fd["foreign"]}
    return {"type": "hasMany", "entity": fd["entity"], "foreign": fd["foreign"], "relationName": fd["relationName"]}


def foreign_link(scope, fd):
    if fd["type"] == "link":
        return {"type": "hasMany", "entity": scope, "foreign": fd["name"]}
    return {"type": "hasMany", "entity": scope, "foreign": fd["name"], "relationName": fd["relationName"]}


def i18n_for(fields):
    out = {"fields": {}, "options": {}, "tooltips": {}, "links": {}}
    for fd in fields:
        out["fields"][fd["name"]] = fd["label"]
        if fd["type"] in ("link", "linkMultiple"): out["links"][fd["name"]] = fd["label"]
        if fd.get("options"): out["options"][fd["name"]] = {v: l for v, l in fd["options"]}
        if fd.get("tip"): out["tooltips"][fd["name"]] = fd["tip"]
    return {k: v for k, v in out.items() if v}


def label_i18n(scope, i18n):
    """Adds relationship-panel labels and the Create button label to an i18n dict."""
    for k, v in LINK_LABELS.get(scope, {}).items():
        i18n.setdefault("links", {})[k] = v
        i18n.setdefault("fields", {})[k] = v
    if scope in CREATE_LABELS:
        i18n.setdefault("labels", {})["Create " + scope] = CREATE_LABELS[scope]
    return i18n


def panel(label, rws):
    p = {"label": label, "rows": rws}
    if label in PANEL_ONLY:   # hide the whole panel (heading too) for other services
        p["dynamicLogicVisible"] = {"conditionGroup": [{"type": "in", "attribute": "service", "value": PANEL_ONLY[label]}]}
    return p


def rows(fields):
    r, cur = [], []
    for fd in fields:
        if fd.get("full"):
            if cur: r.append(cur + [False] * (2 - len(cur))); cur = []
            r.append([{"name": fd["name"], "fullWidth": True}])
        else:
            cur.append({"name": fd["name"]})
            if len(cur) == 2: r.append(cur); cur = []
    if cur: r.append(cur + [False])
    return r


def dynamic_logic(fields):
    out = {}
    for fd in fields:
        if fd.get("only"):
            out[fd["name"]] = {"visible": {"conditionGroup": [{"type": "in", "attribute": "service", "value": fd["only"]}]}}
    return out


BASE_FIELDS = {
    "description": {"type": "text", "rows": 3},
    "createdAt": {"type": "datetime", "readOnly": True}, "modifiedAt": {"type": "datetime", "readOnly": True},
    "createdBy": {"type": "link", "readOnly": True, "view": "views/fields/user"},
    "modifiedBy": {"type": "link", "readOnly": True, "view": "views/fields/user"},
    "assignedUser": {"type": "link", "view": "views/fields/assigned-user"},
}
BASE_LINKS = {"createdBy": {"type": "belongsTo", "entity": "User"}, "modifiedBy": {"type": "belongsTo", "entity": "User"},
              "assignedUser": {"type": "belongsTo", "entity": "User"}}


def php(v):
    """A Python value as a PHP literal (for the generated field lists)."""
    if isinstance(v, dict): return "[" + ", ".join("%s => %s" % (php(k), php(x)) for k, x in v.items()) + "]"
    if isinstance(v, (list, tuple)): return "[" + ", ".join(php(x) for x in v) + "]"
    if v is None: return "null"
    if isinstance(v, bool): return "true" if v else "false"
    if isinstance(v, int): return str(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_fields(fields):
    """name => [type, maxLength, {value: label} | null] for SeasideRequest.php."""
    out = {}
    for fd in fields:
        if fd["type"] in ("link", "linkMultiple", "file"): continue
        d = field_def(fd)
        out[fd["name"]] = [fd["type"], d.get("maxLength", 5000), dict(fd["options"]) if fd.get("options") else None]
    return out


ROUTES = [{"route": "/Seaside/request", "method": "post", "actionClassName": "Espo\\Custom\\Api\\SeasideRequest", "noAuth": True},
          {"route": "/Seaside/babyArrived", "method": "post", "actionClassName": "Espo\\Custom\\Api\\SeasideBabyArrived", "noAuth": True},
          {"route": "/Seaside/moreHours", "method": "post", "actionClassName": "Espo\\Custom\\Api\\SeasideMoreHours", "noAuth": True}]


def build():
    """Returns {relative path: content} for the whole custom tree."""
    files = {}
    R = "custom/Espo/Custom/Resources/"
    J = lambda o: json.dumps(o, indent=4, ensure_ascii=False) + "\n"
    extra_links = {}   # scope -> {link: def} added to other entities

    # new entities
    for scope, e in NEW.items():
        fields = {fd["name"]: field_def(fd) for fd in e["fields"]}
        fields["name"].pop("isCustom", None)
        for k, v in BASE_FIELDS.items(): fields.setdefault(k, v)
        links = dict(BASE_LINKS)
        for fd in e["fields"]:
            if fd["type"] in ("link", "linkMultiple"):
                links[fd["name"]] = link_def(fd)
                extra_links.setdefault(fd["entity"], {})[fd["foreign"]] = foreign_link(scope, fd)
        files[R + "metadata/entityDefs/%s.json" % scope] = {"fields": fields, "links": links,
                                                            "collection": {"orderBy": "createdAt", "order": "desc"}}
        files[R + "metadata/scopes/%s.json" % scope] = {
            "entity": True, "layouts": True, "tab": e.get("tab", True), "acl": True, "module": "Custom", "customizable": True,
            "importable": True, "stream": False, "disabled": False, "type": "Base", "object": True, "isCustom": True,
            **e.get("scope", {})}
        files[R + "metadata/clientDefs/%s.json" % scope] = dict({"controller": "controllers/record", "iconClass": e["icon"]},
                                                               **({"color": e["color"]} if e.get("color") else {}), **e.get("client", {}))
        i18n = i18n_for(e["fields"])
        files[R + "i18n/en_US/%s.json" % scope] = label_i18n(scope, i18n)
        if e.get("panels"):   # several labelled panels (the Postpartum plan)
            files[R + "layouts/%s/detail.json" % scope] = [{"label": lbl, "rows": rows(g)} for lbl, g in e["panels"]]
        else:
            files[R + "layouts/%s/detail.json" % scope] = [{"label": e["label"], "rows": rows([fd for fd in e["fields"] if fd["type"] != "linkMultiple"] +
                                                                                            [fd for fd in e["fields"] if fd["type"] == "linkMultiple"])}]
        files[R + "layouts/%s/list.json" % scope] = [{"name": n, "link": i == 0} for i, n in enumerate(e["list"])]
        files[R + "layouts/%s/detailSmall.json" % scope] = files[R + "layouts/%s/detail.json" % scope]
        for kind in ("Entities", "Controllers", "Services", "Repositories"):
            files["custom/Espo/Custom/%s/%s.php" % (kind, scope)] = (
                "<?php\nnamespace Espo\\Custom\\%s;\n\nclass %s extends \\Espo\\Core\\Templates\\%s\\Base\n{\n}\n" % (kind, scope, kind))

    # Lead = Request
    lead_fields = [fd for g in REQUEST.values() for fd in g] + [fd for g in FAMILY.values() for fd in g] + ROBOT
    lf = {fd["name"]: field_def(fd) for fd in lead_fields}
    ll = {fd["name"]: link_def(fd) for fd in lead_fields if fd["type"] == "link"}
    for fd in lead_fields:
        if fd["type"] == "link": extra_links.setdefault(fd["entity"], {})[fd["foreign"]] = foreign_link("Lead", fd)
    for k, v in extra_links.pop("Lead", {}).items(): ll[k] = v
    ll.update(file_links(lead_fields))
    files[R + "metadata/entityDefs/Lead.json"] = {"fields": lf, "links": ll}
    files[R + "metadata/clientDefs/Lead.json"] = {"dynamicLogic": {"fields": dynamic_logic(lead_fields)}}
    li = label_i18n("Lead", i18n_for(lead_fields))
    li["fields"]["description"] = "Anything else from the family"
    files[R + "i18n/en_US/Lead.json"] = li
    panels = [{"label": "Decision", "rows": rows(REQUEST["Decision"])},
              {"label": "Family", "rows": [[{"name": "name"}, {"name": "emailAddress"}], [{"name": "phoneNumber"}, {"name": "contactPref"}],
                                           [{"name": "address"}, False]] + rows([fd for fd in FAMILY["Getting in"] if fd["name"] != "contactPref"])}]
    for g in ("When", "Baby"): panels.append(panel(g, rows(REQUEST[g])))
    for g in ("Emergency contacts", "Health and insurance", "Home", "Driving"): panels.append(panel(g, rows(FAMILY[g])))
    panels.append(panel("While Amanda's there", rows(REQUEST["While Amanda's there"])))
    panels.append({"label": "How the family does things", "rows": rows(FAMILY["How the family does things"]) + [[{"name": "description", "fullWidth": True}]]})
    panels.append({"label": "Agreement", "rows": rows(REQUEST["Agreement"])})
    files[R + "layouts/Lead/detail.json"] = panels
    files[R + "layouts/Lead/list.json"] = [{"name": "name", "link": True}, {"name": "service"}, {"name": "status"},
                                           {"name": "careStartDate"}, {"name": "startAt"}, {"name": "createdAt"}]
    files[R + "layouts/Lead/relationships.json"] = ["carePlans", "requestChildren", "meetings", "careBookings"]

    # Account = Family
    fam = [fd for g in FAMILY.values() for fd in g] + FAMILY_ONLY
    af = {fd["name"]: field_def(fd) for fd in fam}
    files[R + "metadata/entityDefs/Account.json"] = {"fields": af, "links": dict(extra_links.pop("Account", {}), **file_links(fam))}
    ai = label_i18n("Account", i18n_for(fam))
    ai["fields"]["description"] = "Notes"
    files[R + "i18n/en_US/Account.json"] = ai
    for scope in ("Contact", "Meeting"):
        files[R + "i18n/en_US/%s.json" % scope] = label_i18n(scope, {})
    files[R + "layouts/Account/detail.json"] = (
        [{"label": "Family", "rows": [[{"name": "name"}, {"name": "phoneNumber"}], [{"name": "emailAddress"}, {"name": "contactPref"}],
                                      [{"name": "billingAddress"}, False]] + rows([fd for fd in FAMILY["Getting in"] if fd["name"] != "contactPref"])
                                      + [[{"name": "familyLink", "fullWidth": True}]]}]
        + [{"label": g, "rows": rows(FAMILY[g])} for g in ("Emergency contacts", "Health and insurance", "Home", "Driving", "How the family does things")]
        + [{"label": "Notes", "rows": [[{"name": "description", "fullWidth": True}]]}])
    # EspoCRM 10 lays out the Account (Family) page with bottomPanelsDetail (tabs), not relationships.json:
    # one plain list of panels, stream last, the sales/support panels off.
    order = ["carePlans", "children", "contacts", "careBookings", "shiftLogs", "familyRequests", "stream"]
    bp = {n: {"index": i, "sticked": False} for i, n in enumerate(order)}
    bp.update({n: {"disabled": True} for n in ("opportunities", "documents", "cases")})
    files[R + "layouts/Account/bottomPanelsDetail.json"] = bp
    files[R + "layouts/Account/relationships.json"] = order[:-1]

    # Contact = Parent, Meeting = Consultation: only the extra links
    for scope in ("Contact", "Child", "CareBooking", "CarePlan", "Meeting"):
        if scope in extra_links:
            path = R + "metadata/entityDefs/%s.json" % scope
            cur = files.get(path, {"fields": {}, "links": {}})
            cur["links"].update(extra_links.pop(scope))
            files[path] = cur
    files[R + "layouts/Contact/relationships.json"] = ["carePlans", "careBookings", "account", "meetings"]
    files[R + "layouts/Child/relationships.json"] = ["babyPlans", "careBookings", "requestEntries"]
    files[R + "layouts/CarePlan/relationships.json"] = ["shifts"]
    files[R + "layouts/CareBooking/relationships.json"] = ["shiftLogs"]
    assert not extra_links, "unhandled links: %s" % extra_links

    # the booking form's endpoint, with its field lists generated from this model
    here = os.path.dirname(os.path.abspath(__file__))
    src = open(os.path.join(here, "php", "SeasideRequest.php")).read()
    src = src.replace("/*FIELDS*/[]/*END*/", php(php_fields([fd for fd in lead_fields if fd not in ROBOT])))   # never the robot's checkpoints
    src = src.replace("/*CHILD_FIELDS*/[]/*END*/", php(php_fields(CHILD)))
    files["custom/Espo/Custom/Api/SeasideRequest.php"] = src
    # Generated constants in the PHP: service labels, the postpartum minimum shift.
    consts = {"/*SERVICES*/[]/*END*/": php(dict(SERVICES[:-1])), "/*PP_MIN_HOURS*/8/*END*/": str(P.PP_MIN_HOURS),
              "/*PP_HOURLY*/60/*END*/": str(P.PP_HOURLY), "/*PP_TOPUP_EARNED_FROM*/100/*END*/": str(P.PP_TOPUP_EARNED_FROM),
              "/*PP_PLANS*/[]/*END*/": php(dict(P.pp_plan_options()))}
    def gen(src):
        for k, v in consts.items(): src = src.replace(k, v)
        return src
    for ep in ("TonightBoard", "FamilyPage"):
        files["custom/Espo/Custom/EntryPoints/%s.php" % ep] = gen(open(os.path.join(here, "php", ep + ".php")).read())
    files["custom/Espo/Custom/Api/SeasideBabyArrived.php"] = gen(open(os.path.join(here, "php", "SeasideBabyArrived.php")).read())
    files["custom/Espo/Custom/Api/SeasideMoreHours.php"] = gen(open(os.path.join(here, "php", "SeasideMoreHours.php")).read())
    files["custom/Espo/Custom/Seaside/Footer.php"] = open(os.path.join(here, "php", "SeasideFooter.php")).read()
    # Hooks (php/Hooks/<Entity>/<Name>.php) and office-side JavaScript (client/<kind>/<name>.js), copied as they are.
    for sub, dest in (("php/Hooks", "custom/Espo/Custom/Hooks"), ("client", "client/custom/src")):
        base = os.path.join(here, sub)
        for root, _, names in os.walk(base):
            for n in sorted(names):
                rel = os.path.relpath(os.path.join(root, n), base)
                files[dest + "/" + rel] = gen(open(os.path.join(root, n)).read())
    files[R + "routes.json"] = ROUTES

    # the Seaside theme (brand colors; logo D, locked 2026-10-11)
    files["client/custom/css/seaside.css"] = open(os.path.join(here, "theme", "seaside.css")).read()
    files["client/custom/img/seaside-logo.svg"] = open(os.path.join(here, "theme", "seaside-logo.svg")).read()
    files[R + "metadata/themes/Seaside.json"] = {
        "stylesheet": "client/custom/css/seaside.css", "stylesheetIframe": "client/css/espo/light-iframe.css",
        "logo": "client/custom/img/seaside-logo.svg", "textColor": "#4A4440", "isDark": False,
        "chartColorList": ["#2F6F86", "#F2C6C2", "#B9CDB4", "#A9D6E5", "#FBE7B5", "#9A5752"],
        "chartColorAlternativeList": ["#2F6F86", "#A9D6E5", "#B9CDB4"],
        "calendarColors": {"": "#2F6F86", "bg": "#E3F2EEb3", "Meeting": "#9A5752", "CareBooking": "#2F6F86", "Task": "#5E7F59"}}

    # names in the office
    files[R + "i18n/en_US/Global.json"] = {
        "scopeNames": dict({k: v[0] for k, v in SCOPE_NAMES.items()}, **{k: e["label"] for k, e in NEW.items()}),
        "scopeNamesPlural": dict({k: v[1] for k, v in SCOPE_NAMES.items()}, **{k: e["plural"] for k, e in NEW.items()}),
        "themes": {"Seaside": "Seaside"},
    }
    return {p: (c if isinstance(c, str) else J(c)) for p, c in files.items()}


if __name__ == "__main__":
    out = sys.argv[1] if len(sys.argv) > 1 else "build"
    for p, c in build().items():
        os.makedirs(os.path.join(out, os.path.dirname(p)), exist_ok=True)
        open(os.path.join(out, p), "w").write(c)
    print("wrote %d files to %s" % (len(build()), out))
