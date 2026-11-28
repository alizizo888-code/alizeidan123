// «مركز الواتساب» رجع جوه الموقع (oxygen11.com/wa-center) بدل ما كان على الـVPS لوحده: الدخول بحساب الموقع نفسه،
// والإشعارات والتطبيق المثبّت كلهم على نفس الدومين، فمفيش تحويل لبرّه ولا تسجيل دخول تاني (المالك، 2026-11-23).
export const HUB_URL = 'https://oxygen11.com/wa-center/'
export const openHub = () => { try { location.assign(HUB_URL) } catch { location.href = HUB_URL } }
