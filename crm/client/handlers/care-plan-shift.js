/**
 * "+" on a Postpartum plan's Shifts panel: the new shift comes pre-filled as a postpartum shift for
 * the plan's family, parent and baby, on the night after the plan's latest shift (or its First night),
 * at the plan's shift time and length. The plan link itself is set by the panel; hours, rate and
 * "In package" are filled by the office when it's saved (Hooks/CareBooking/PlanShift.php).
 * Times are Clearwater local (the business's time zone), stored in UTC like every EspoCRM datetime.
 */
define('custom:handlers/care-plan-shift', ['moment'], (moment) => {
    const TZ = 'America/New_York';
    const DB = 'YYYY-MM-DD HH:mm:ss';

    return class {
        constructor(helper, options) {}

        getAttributes(model) {
            const family = (model.get('familyName') || '').replace(/ \(FAKE\)$/, '').replace(/ family$/i, '');
            const [hh, mm] = (model.get('shiftStart') || '22:00').split(':').map(Number);
            const length = model.get('shiftHours') || 8;
            const attributes = {
                service: 'pp',
                status: 'Confirmed',
                familyId: model.get('familyId'),
                familyName: model.get('familyName'),
                contactId: model.get('contactId'),
                contactName: model.get('contactName'),
            };
            if (model.get('babyId')) {
                attributes.childrenIds = [model.get('babyId')];
                attributes.childrenNames = {[model.get('babyId')]: model.get('babyName')};
            }

            const on = (day) => {   // day: 'YYYY-MM-DD' (local)
                const start = moment.tz(day, 'YYYY-MM-DD', TZ).hour(hh).minute(mm);
                const end = start.clone().add(length, 'hours');
                return Object.assign(attributes, {
                    dateStart: start.clone().utc().format(DB),
                    dateEnd: end.clone().utc().format(DB),
                    name: family + ' · Postpartum · ' + start.format('ddd MMM D'),
                });
            };

            return Espo.Ajax.getRequest('CarePlan/' + model.id + '/shifts', {maxSize: 1, orderBy: 'dateStart', order: 'desc', select: 'dateStart'})
                .then(r => {
                    const last = (r.list || [])[0];
                    if (last && last.dateStart) {
                        // a shift that ends after midnight still belongs to the night it started
                        return on(moment.utc(last.dateStart, DB).tz(TZ).add(1, 'day').format('YYYY-MM-DD'));
                    }
                    return on(model.get('startDate') || moment().tz(TZ).format('YYYY-MM-DD'));
                })
                .catch(() => on(moment().tz(TZ).format('YYYY-MM-DD')));
        }
    };
});
