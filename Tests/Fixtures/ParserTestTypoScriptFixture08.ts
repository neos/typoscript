/**
 *  TypoScript Fixture 08
 *
 *  This fixture serves for testing the copy operator for
 *  objects, variables and properties.
 *
 */
namespace: default = T3_TYPO3_TypoScript
 
object1 = Text
object1.value = "Hello world!"

object2 < object1

lib {
	object3 = Text
	object3.value = "Another message"
	
	object4 < .object3
	
	object5 < lib.object3
	
	object6 < object1
}

object7 = Text
object7.value < object1.value

object8 = Text
object8.$message < object1
object8.value = 'I say "$message"'
