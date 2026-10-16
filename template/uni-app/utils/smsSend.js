const pending = new Map();
const storageKey = phone => 'sms.retry.' + phone;
export function smsDeadline(phone) {
    return Number(uni.getStorageSync(storageKey(phone))) || 0;
}
export function sendSms(data, post) {
    const phone = String(data.phone || '');
    if (pending.has(phone)) return pending.get(phone);
    const remaining = Math.ceil((smsDeadline(phone) - Date.now()) / 1000);
    if (remaining > 0) return Promise.reject('请在' + remaining + '秒后重新获取验证码');
    const task = Promise.resolve().then(() => post(data)).then(res => {
        const seconds = Number(res.data && res.data.retry_after);
        if (seconds > 0) {
            uni.setStorageSync(storageKey(phone), Date.now() + seconds * 1000);
            uni.$emit('sms:deadline', phone);
        }
        if (res.status !== 200) throw (res.msg || '短信发送失败，请稍后重试');
        return res;
    }).finally(() => pending.delete(phone));
    pending.set(phone, task);
    return task;
}
